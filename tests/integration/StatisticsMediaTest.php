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

namespace Xibo\Tests\Integration;

use Jenssegers\Date\Date;
use Xibo\Helper\Random;
use Xibo\OAuth2\Client\Entity\XiboDisplay;
use Xibo\OAuth2\Client\Entity\XiboLayout;
use Xibo\OAuth2\Client\Entity\XiboLibrary;
use Xibo\OAuth2\Client\Entity\XiboPlaylist;
use Xibo\OAuth2\Client\Entity\XiboStats;
use Xibo\Tests\Helper\DisplayHelperTrait;
use Xibo\Tests\Helper\LayoutHelperTrait;
use Xibo\Tests\LocalWebTestCase;

/**
 * Class StatisticsMediaTest
 * @package Xibo\Tests\Integration
 */
class StatisticsMediaTest extends LocalWebTestCase
{

    use LayoutHelperTrait, DisplayHelperTrait;

    /** @var XiboLayout */
    protected $layout;

    /** @var XiboDisplay */
    protected $display;

    /** @var XiboLibrary */
    protected $media;

    /** @var XiboLibrary */
    protected $media2;

    /** @var \Xibo\OAuth2\Client\Entity\XiboWidget */
    private $widget;

    /** @var \Xibo\OAuth2\Client\Entity\XiboWidget */
    private $widget2;

    /**
     * setUp - called before every test automatically
     */
    public function setup()
    {
        parent::setup();

        // Create a Layout
        $this->layout = $this->createLayout();

        // Create a Display
        $this->display = $this->createDisplay();
        $this->displaySetLicensed($this->display);

        // Upload some media
        $this->media = (new XiboLibrary($this->getEntityProvider()))
            ->create(Random::generateString(), PROJECT_ROOT . '/tests/resources/xts-night-001.jpg');

        $this->media2 = (new XiboLibrary($this->getEntityProvider()))
            ->create(Random::generateString(), PROJECT_ROOT . '/tests/resources/xts-layout-003-background.jpg');

        // Checkout our Layout and add some Widgets to it.
        $layout = $this->checkout($this->layout);

        // Add another region
        // Assign media to the layouts default region.
        $playlist = (new XiboPlaylist($this->getEntityProvider()))->assign([$this->media->mediaId, $this->media2->mediaId], 10, $layout->regions[0]->regionPlaylist->playlistId);

        // Get Widget Ids
        $this->widget = $playlist->widgets[0];
        $this->widget2 = $playlist->widgets[1];

        // Publish the Layout
        $this->layout = $this->publish($this->layout);

        $this->getLogger()->debug('Finished Setup');

    }

    /**
     * tearDown - called after every test automatically
     */
    public function tearDown()
    {
        $this->getLogger()->debug('Tear Down');

        parent::tearDown();

        // Delete the Layout we've been working with
        $this->deleteLayout($this->layout);

        // Delete the Display
        $this->deleteDisplay($this->display);

        // Delete the media records
        $this->media->deleteAssigned();
        $this->media2->deleteAssigned();

        // Delete stat records
        self::$container->timeSeriesStore->deleteStats(Date::now(), Date::createFromFormat("Y-m-d H:i:s", '2018-02-12 00:00:00'));
    }

    /**
     * Check if proof of play statistics are correct
     */
    public function testProof()
    {
        $type = 'media';

        $hardwareId = $this->display->license;

        // Set start and date time
        //
        // $fromDt =  '2018-02-12 00:00:00';
        // $toDt =  '2018-02-17 00:00:00';

        // Add stats to the DB -  known set
        //
        // 1 layout, 2 region, 2 medias (1 per region)
        // type,start,end,layout,media
        // media,2018-02-12 00:00:00, 2018-02-13 00:00:00, L1, M1
        // media,2018-02-13 00:00:00, 2018-02-14 00:00:00, L1, M1
        // media,2018-02-16 00:00:00, 2018-02-17 12:00:00, L1, M1
        // media,2018-02-14 00:00:00, 2018-02-15 00:00:00, L1, M2
        // media,2018-02-15 00:00:00, 2018-02-16 00:00:00, L1, M2
        // media,2018-02-16 00:00:00, 2018-02-16 12:00:00, L1, M2
        //
        // Result
        // M1 60 hours
        // M2 60 hours

        // First insert - M1
        $response = $this->getXmdsWrapper()->SubmitStats($hardwareId,
                '<stats>
                        <stat fromdt="2018-02-12 00:00:00" 
                        todt="2018-02-13 00:00:00" 
                        type="'.$type.'" 
                        scheduleid="0" 
                        layoutid="'.$this->layout->layoutId.'" 
                        mediaid="'.$this->widget->widgetId.'"/>
                    </stats>');
        $this->assertSame(true, $response);

        // Second insert - M1
        $response = $this->getXmdsWrapper()->SubmitStats($hardwareId,
            '<stats>
                        <stat fromdt="2018-02-13 00:00:00"
                        todt="2018-02-14 00:00:00"
                        type="'.$type.'" 
                        scheduleid="0"
                        layoutid="'.$this->layout->layoutId.'"
                        mediaid="'.$this->widget->widgetId.'"/>
                    </stats>');
        $this->assertSame(true, $response);

        // Third insert - M1
        $response = $this->getXmdsWrapper()->SubmitStats($hardwareId,
            '<stats>
                        <stat fromdt="2018-02-16 12:00:00"
                        todt="2018-02-17 00:00:00"
                        type="'.$type.'"
                        scheduleid="0"
                        layoutid="'.$this->layout->layoutId.'"
                        mediaid="'.$this->widget->widgetId.'"/>
                    </stats>');
        $this->assertSame(true, $response);

        // First insert - M2
        $response = $this->getXmdsWrapper()->SubmitStats($hardwareId,
                '<stats>
                        <stat fromdt="2018-02-14 00:00:00"
                        todt="2018-02-15 00:00:00"
                        type="'.$type.'"
                        scheduleid="0"
                        layoutid="'.$this->layout->layoutId.'"
                        mediaid="'.$this->widget2->widgetId.'"/>
                    </stats>');
        $this->assertSame(true, $response);

        // Second insert - M2
        $response = $this->getXmdsWrapper()->SubmitStats($hardwareId,
            '<stats>
                        <stat fromdt="2018-02-15 00:00:00"
                        todt="2018-02-16 00:00:00"
                        type="'.$type.'"
                        scheduleid="0"
                        layoutid="'.$this->layout->layoutId.'"
                        mediaid="'.$this->widget2->widgetId.'"/>
                    </stats>');
        $this->assertSame(true, $response);

        // Third insert - M2
        $response = $this->getXmdsWrapper()->SubmitStats($hardwareId,
            '<stats>
                        <stat fromdt="2018-02-16 00:00:00"
                        todt="2018-02-16 12:00:00"
                        type="'.$type.'"
                        scheduleid="0"
                        layoutid="'.$this->layout->layoutId.'"
                        mediaid="'.$this->widget2->widgetId.'"/>
                    </stats>');
        $this->assertSame(true, $response);

        // Get stats and see if they match with what we expect
        $this->client->get('/stats' , [
            'fromDt' => '2018-02-12 00:00:00',
            'toDt' => '2018-02-17 00:00:00',
            'displayId' => $this->display->displayId,
            'type' => $type
        ]);

        $this->assertSame(200, $this->client->response->status());
        $this->assertNotEmpty($this->client->response->body());
        $object = json_decode($this->client->response->body());
        //$this->getLogger()->debug($this->client->response->body());
        $this->assertObjectHasAttribute('data', $object, $this->client->response->body());
        $stats = (new XiboStats($this->getEntityProvider()))->get(['fromDt' => '2018-02-12 00:00:00', 'toDt' => '2018-02-17 00:00:00', 'layoutId' => $this->layout->layoutId]);
        // print_r($stats);
        $this->assertNotEquals(0, count($stats));

    }
}
