<?php
/**
 * Copyright (C) 2019 Xibo Signage Ltd
 *
 * Xibo - Digital Signage - http://www.xibo.org.uk
 *
 * This file is part of Xibo.
 *
 * Xibo is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * Xibo is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Xibo.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Xibo\XTR;
use Xibo\Entity\Task;
use Xibo\Entity\User;
use Xibo\Exception\NotFoundException;
use Xibo\Factory\LayoutFactory;
use Xibo\Factory\TaskFactory;
use Xibo\Factory\UserFactory;

/**
 * Class StatsMigrationTask
 * @package Xibo\XTR
 */
class StatsMigrationTask implements TaskInterface
{
    use TaskTrait;

    /** @var  User */
    private $archiveOwner;

    /** @var UserFactory */
    private $userFactory;

    /** @var TaskFactory */
    private $taskFactory;

    /** @var LayoutFactory */
    private $layoutFactory;

    private $archiveExist;

    /** @inheritdoc */
    public function setFactories($container)
    {
        $this->userFactory = $container->get('userFactory');
        $this->taskFactory = $container->get('taskFactory');
        $this->layoutFactory = $container->get('layoutFactory');
        return $this;
    }

    /** @inheritdoc */
    public function run()
    {
        $this->migrateStats();
    }

    public function migrateStats()
    {
        // Config options
        $options = [
            'killSwitch' => $this->getOption('killSwitch', 0),
            'numberOfRecords' => $this->getOption('numberOfRecords', 10000),
            'numberOfLoops' => $this->getOption('numberOfLoops', 1000),
            'pauseBetweenLoops' => $this->getOption('pauseBetweenLoops', 10),
            'optimiseOnComplete' => $this->getOption('optimiseOnComplete', 1),
        ];

        // read configOverride
        $configOverrideFile = $this->getOption('configOverride', '');
        if (!empty($configOverrideFile) && file_exists($configOverrideFile)) {
            $options = array_merge($options, json_decode(file_get_contents($configOverrideFile), true));
        }

        if ($options['killSwitch'] == 0) {

            // Check stat_archive table exists
            $this->archiveExist = $this->store->exists('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name', [
                'name' => 'stat_archive'
            ]);

            // Get timestore engine
            $timeSeriesStore = $this->timeSeriesStore->getEngine();

            if ($timeSeriesStore == 'mongodb') {

                // If no records in both the stat and stat_archive then disable the task
                $statSql = $this->store->getConnection()->prepare('SELECT statId FROM stat LIMIT 1');
                $statSql->execute();

                $statArchiveSqlCount =  0;
                if ( $this->archiveExist === true) {
                    $statArchiveSql = $this->store->getConnection()->prepare('SELECT statId FROM stat_archive LIMIT 1');
                    $statArchiveSql->execute();
                    $statArchiveSqlCount = $statArchiveSql->rowCount();
                }

                if( ($statSql->rowCount() == 0) && ($statArchiveSqlCount == 0) ) {

                    $this->runMessage = '## Stat migration to Mongo' . PHP_EOL ;
                    $this->appendRunMessage('- Both stat_archive and stat is empty. '. PHP_EOL);

                    // Disable the task
                    $this->log->debug('Stats migration task is disabled as stat_archive and stat is empty');
                    $this->disableTask();
                }

                $this->moveStatsToMongoDb($options);
            }

            // If when the task runs it finds that MongoDB is disabled,
            // and there isn't a stat_archive table, then it should disable itself and not run again
            // (work is considered to be done at that point).
            else {

                if ($this->archiveExist == true) {
                    $this->runMessage = '## Moving from stat_archive to stat (MySQL)' . PHP_EOL ;
                    $this->moveStatsFromStatArchiveToStatMysql($options);

                } else {
                    // Disable the task

                    $this->runMessage = '## Moving from stat_archive to stat (MySQL)' . PHP_EOL ;
                    $this->appendRunMessage('- Table stat_archive does not exist.' . PHP_EOL);

                    $this->log->debug('Table stat_archive does not exist.');
                    $this->disableTask();
                }
            }
        }
    }

    public function moveStatsFromStatArchiveToStatMysql($options)
    {

        $fileName = $this->config->getSetting('LIBRARY_LOCATION') . '.watermark_stat_archive_mysql.txt';

        // Get low watermark from file
        $watermark = $this->getWatermarkFromFile($fileName, 'stat_archive');

        $numberOfLoops = 0;

        while ($watermark > 0) {
            $count = 0;
            $stats = $this->store->getConnection()->prepare('
                SELECT statId, type, statDate, scheduleId, displayId, layoutId, mediaId, widgetId, start, `end`, tag
                  FROM stat_archive 
                 WHERE statId < :watermark 
                ORDER BY statId DESC LIMIT :limit
            ');
            $stats->bindParam(':watermark', $watermark, \PDO::PARAM_INT);
            $stats->bindParam(':limit', $options['numberOfRecords'], \PDO::PARAM_INT);

            // Run the select
            $stats->execute();

            // Keep count how many stats we've inserted
            $recordCount = $stats->rowCount();
            $count+= $recordCount;

            // End of records
            if ($this->checkEndOfRecords($recordCount, $fileName) === true) {

                $this->appendRunMessage(PHP_EOL. '# End of records.' . PHP_EOL. '- Dropping stat_archive.');
                $this->log->debug('End of records in stat_archive (migration to MYSQL). Dropping table.');

                // Drop the stat_archive table
                $this->store->update('DROP TABLE `stat_archive`;', []);

                $this->appendRunMessage(__('Done.'. PHP_EOL));

                // Disable the task
                $this->disableTask();

                break;
            }

            // Loops limit end - task will need to rerun again to start from the saved watermark
            if ($this->checkLoopLimits($numberOfLoops, $options['numberOfLoops'], $fileName, $watermark) === true) {
                break;
            }
            $numberOfLoops++;

            $temp = [];

            foreach ($stats->fetchAll() as $stat) {

                $columns = 'type, statDate, scheduleId, displayId, campaignId, layoutId, mediaId, widgetId, `start`, `end`, tag, duration, `count`';
                $values = ':type, :statDate, :scheduleId, :displayId, :campaignId, :layoutId, :mediaId, :widgetId, :start, :end, :tag, :duration, :count';

                // Get campaignId
                if (($stat['type'] != 'event') && ($stat['layoutId'] != null)) {
                    try {
                        // Search the campaignId in the temp array first to reduce query in layouthistory
                        if (array_key_exists($stat['layoutId'], $temp) ) {
                            $campaignId = $temp[$stat['layoutId']];
                        } else {
                            $campaignId = $this->layoutFactory->getCampaignIdFromLayoutHistory($stat['layoutId']);
                            $temp[$stat['layoutId']] = $campaignId;
                        }
                    } catch (NotFoundException $error) {
                        continue;
                    }
                } else {
                    $campaignId = 0;
                }

                $params = [
                    'type' => $stat['type'],
                    'statDate' =>  $this->date->parse($stat['statDate'])->format('U'),
                    'scheduleId' => (int) $stat['scheduleId'],
                    'displayId' => (int) $stat['displayId'],
                    'campaignId' => $campaignId,
                    'layoutId' => (int) $stat['layoutId'],
                    'mediaId' => (int) $stat['mediaId'],
                    'widgetId' => (int) $stat['widgetId'],
                    'start' => $this->date->parse($stat['start'])->format('U'),
                    'end' => $this->date->parse($stat['end'])->format('U'),
                    'tag' => $stat['tag'],
                    'duration' => isset($stat['duration']) ? (int) $stat['duration'] : $this->date->parse($stat['end'])->format('U') - $this->date->parse($stat['start'])->format('U'),
                    'count' => isset($stat['count']) ? (int) $stat['count'] : 1,
                ];

                $watermark = $stat['statId'];

                // Do the insert
                $this->store->insert('INSERT INTO `stat` (' . $columns . ') VALUES (' . $values . ')', $params);
                $this->store->commitIfNecessary();

            }

            // Give SQL time to recover
            if ($watermark > 0) {
                $this->appendRunMessage('- '. $count. ' rows migrated.');

                $this->log->debug('MYSQL stats migration from stat_archive to stat. '.$count.' rows effected, sleeping.');
                sleep($options['pauseBetweenLoops']);
            }
        }
    }

    public function moveStatsToMongoDb($options)
    {

        // Migration from stat table to Mongo
        $this->migrationStatToMongo($options);

        // Migration from stat_archive table to Mongo
        // After migration delete only stat_archive
        if ($this->archiveExist == true) {
            $this->migrationStatArchiveToMongo($options);
        }
    }

    function migrationStatToMongo($options) {

        $this->appendRunMessage('## Moving from stat to Mongo');

        // Stat Archive Task
        $archiveTask = $this->taskFactory->getByClass('\Xibo\XTR\\StatsArchiveTask');

        $fileName = $this->config->getSetting('LIBRARY_LOCATION') . '.watermark_stat_mongo.txt';

        // Get low watermark from file
        $watermark = $this->getWatermarkFromFile($fileName, 'stat');

        $sql = $this->store->getConnection()->prepare('SELECT statId FROM stat WHERE statId < :watermark ORDER BY statId DESC LIMIT 1');
        $sql->bindParam(':watermark', $watermark, \PDO::PARAM_INT);
        $sql->execute();

        // Mark the Stats Archiver as disabled if there are records in stat table
        if ($sql->rowCount() > 0) {

            // Quit the StatsArchiveTask if it is running
            if ($archiveTask->status == Task::$STATUS_RUNNING) {

                $this->appendRunMessage('Quitting the stat migration task as stat archive task is running');
                $this->log->debug('Quitting the stat migration task as stat archive task is running.');
                return;
            }
            $archiveTask->isActive = 0;
            $archiveTask->save();
            $this->store->commitIfNecessary();
        }

        $numberOfLoops = 0;

        while ($watermark > 0) {
            $count = 0;
            $stats = $this->store->getConnection()
                ->prepare('SELECT * FROM stat WHERE statId < :watermark ORDER BY statId DESC LIMIT :limit');
            $stats->bindParam(':watermark', $watermark, \PDO::PARAM_INT);
            $stats->bindParam(':limit', $options['numberOfRecords'], \PDO::PARAM_INT);

            // Run the select
            $stats->execute();

            // Keep count how many stats we've inserted
            $recordCount = $stats->rowCount();
            $count+= $recordCount;

            // End of records
            if ($this->checkEndOfRecords($recordCount, $fileName) === true) {

                // Enable the StatsArchiver task
                $archiveTask->isActive = 1;
                $archiveTask->save();
                $this->store->commitIfNecessary();

                $this->appendRunMessage(PHP_EOL. '# End of records.' . PHP_EOL. '- Truncating and Optimising stat.');
                $this->log->debug('End of records in stat table. Truncate and Optimise.');

                // Truncate stat table
                $this->store->update('TRUNCATE TABLE stat', []);

                // Optimize stat table
                if ($options['optimiseOnComplete'] == 1) {
                    $this->store->update('OPTIMIZE TABLE stat', []);
                }

                $this->appendRunMessage(__('Done.'. PHP_EOL));

                break;
            }

            // Loops limit end - task will need to rerun again to start from the saved watermark
            if ($this->checkLoopLimits($numberOfLoops, $options['numberOfLoops'], $fileName, $watermark) === true) {
                break;
            }
            $numberOfLoops++;

            $statDataMongo = [];

            foreach ($stats->fetchAll() as $stat) {

                $entry = [];

                $entry['type'] = $stat['type'];
                $entry['fromDt'] = $this->date->parse($stat['start'], 'U');
                $entry['toDt'] = $this->date->parse($stat['end'], 'U');
                $entry['scheduleId'] = (int) $stat['scheduleId'];
                $entry['mediaId'] = (int) $stat['mediaId'];
                $entry['layoutId'] = (int) $stat['layoutId'];
                $entry['displayId'] = (int) $stat['displayId'];
                $entry['campaignId'] = (int) $stat['campaignId'];
                $entry['tag'] = $stat['tag'];
                $entry['widgetId'] = (int) $stat['widgetId'];
                $entry['duration'] = (int) $stat['duration'];
                $entry['count'] = (int) $stat['count'];

                $statDataMongo[] = $entry;

                $watermark = $stat['statId'];
            }

            // Do the insert in chunk
            if (count($statDataMongo) > 0) {
                $this->timeSeriesStore->addStat($statDataMongo);
            } else {
                $this->appendRunMessage('No stat to migrate from stat to mongo');
                $this->log->debug('No stat to migrate from stat to mongo');
            }

            // Give Mongo time to recover
            if ($watermark > 0) {
                $this->appendRunMessage('- '. $count. ' rows migrated.');
                $this->log->debug('Mongo stats migration from stat. '.$count.' rows effected, sleeping.');
                sleep($options['pauseBetweenLoops']);
            }
        }
    }

    function migrationStatArchiveToMongo($options) {

        $this->appendRunMessage(PHP_EOL. '## Moving from stat_archive to Mongo');
        $fileName = $this->config->getSetting('LIBRARY_LOCATION') . '.watermark_stat_archive_mongo.txt';

        // Get low watermark from file
        $watermark = $this->getWatermarkFromFile($fileName, 'stat_archive');

        $numberOfLoops = 0;

        while ($watermark > 0) {
            $count = 0;
            $stats = $this->store->getConnection()->prepare('
                SELECT statId, type, statDate, scheduleId, displayId, layoutId, mediaId, widgetId, start, `end`, tag
                  FROM stat_archive 
                 WHERE statId < :watermark 
                ORDER BY statId DESC LIMIT :limit
            ');
            $stats->bindParam(':watermark', $watermark, \PDO::PARAM_INT);
            $stats->bindParam(':limit', $options['numberOfRecords'], \PDO::PARAM_INT);

            // Run the select
            $stats->execute();

            // Keep count how many stats we've inserted
            $recordCount = $stats->rowCount();
            $count+= $recordCount;

            // End of records
            if ($this->checkEndOfRecords($recordCount, $fileName) === true) {

                $this->appendRunMessage(PHP_EOL. '# End of records.' . PHP_EOL. '- Dropping stat_archive.');
                $this->log->debug('End of records in stat_archive (migration to Mongo). Dropping table.');

                // Drop the stat_archive table
                $this->store->update('DROP TABLE `stat_archive`;', []);

                $this->appendRunMessage(__('Done.'. PHP_EOL));

                break;
            }

            // Loops limit end - task will need to rerun again to start from the saved watermark
            if ($this->checkLoopLimits($numberOfLoops, $options['numberOfLoops'], $fileName, $watermark) === true) {
                break;
            }
            $numberOfLoops++;

            $statDataMongo = [];
            $temp = [];

            foreach ($stats->fetchAll() as $stat) {

                $entry = [];

                // Get campaignId
                if (($stat['type'] != 'event') && ($stat['layoutId'] != null)) {
                    try {
                        // Search the campaignId in the temp array first to reduce query in layouthistory
                        if (array_key_exists($stat['layoutId'], $temp) ) {
                            $campaignId = $temp[$stat['layoutId']];
                        } else {
                            $campaignId = $this->layoutFactory->getCampaignIdFromLayoutHistory($stat['layoutId']);
                            $temp[$stat['layoutId']] = $campaignId;
                        }
                    } catch (NotFoundException $error) {
                        continue;
                    }
                } else {
                    $campaignId = 0;
                }

                $start = $this->date->parse($stat['start']);
                $end = $this->date->parse($stat['end']);

                $entry['type'] = $stat['type'];
                $entry['fromDt'] = $start;
                $entry['toDt'] = $end;
                $entry['scheduleId'] = (int) $stat['scheduleId'];
                $entry['displayId'] = (int) $stat['displayId'];
                $entry['campaignId'] = (int) $campaignId;
                $entry['layoutId'] = (int) $stat['layoutId'];
                $entry['mediaId'] = (int) $stat['mediaId'];
                $entry['tag'] = $stat['tag'];
                $entry['widgetId'] = (int) $stat['widgetId'];
                $entry['duration'] = $end->diffInSeconds($start);
                $entry['count'] = isset($stat['count']) ? (int) $stat['count'] : 1;

                $statDataMongo[] = $entry;

                $watermark = $stat['statId'];
            }

            // Do the insert in chunk
            if (count($statDataMongo) > 0) {
                $this->timeSeriesStore->addStat($statDataMongo);
            } else {
                $this->appendRunMessage('No stat to migrate from stat archive to mongo');
                $this->log->debug('No stat to migrate from stat archive to mongo');
            }

            // Give Mongo time to recover
            if ($watermark > 0) {
                $this->appendRunMessage('- '. $count. ' rows migrated.');
                $this->log->debug('Mongo stats migration from stat_archive. '.$count.' rows effected, sleeping.');
                sleep($options['pauseBetweenLoops']);
            }
        }
    }

    // Get low watermark from file
    function getWatermarkFromFile($fileName, $tableName) {

        if (file_exists($fileName)) {

            $file = fopen($fileName, 'r');
            $line = fgets($file);
            fclose($file);
            $watermark = (int) $line;

        } else {

            // Save mysql low watermark in file if .watermark.txt file is not found
            $statId = $this->store->select('SELECT MAX(statId) as statId FROM '.$tableName, []);
            $watermark = (int) $statId[0]['statId'];

            $out = fopen($fileName, 'w');
            fwrite($out, $watermark);
            fclose($out);
        }

        // We need to increase it
        $watermark+= 1;
        $this->appendRunMessage('- Initial watermark is '.$watermark);

        return $watermark;
    }

    // Check if end of records
    function checkEndOfRecords($recordCount, $fileName) {

        if($recordCount == 0) {
            // No records in stat, save watermark in file
            $watermark = -1;

            $out = fopen($fileName, 'w');
            fwrite($out, $watermark);
            fclose($out);

            return true;
        }

        return false;
    }

    // Check loop limits
    function checkLoopLimits($numberOfLoops, $optionsNumberOfLoops, $fileName, $watermark) {

        if($numberOfLoops == $optionsNumberOfLoops) {

            // Save watermark in file
            $watermark = $watermark - 1;
            $this->log->debug(' Loop reached limit. Watermark is now '.$watermark);

            $out = fopen($fileName, 'w');
            fwrite($out, $watermark);
            fclose($out);

            return true;
        }

        return false;
    }

    // Disable the task
    function disableTask() {

        $this->appendRunMessage('# Disabling task.');
        $this->log->debug('Disabling task.');

        $this->getTask()->isActive = 0;
        $this->getTask()->save();

        $this->appendRunMessage(__('Done.'. PHP_EOL));

        return;
    }
}
