<?php
/*
 * Spring Signage Ltd - http://www.springsignage.com
 * Copyright (C) 2015 Spring Signage Ltd
 * (CommandTest.php)
 */

namespace Xibo\Tests\Integration;

use Xibo\OAuth2\Client\Entity\XiboCommand;


class CommandTest extends LocalWebTestCase
{

	/**
     * Shows this user commands
     */
    public function testListAll()
    {
        $this->client->get('/command');

        $this->assertSame(200, $this->client->response->status());
        $this->assertNotEmpty($this->client->response->body());

        $object = json_decode($this->client->response->body());
//        fwrite(STDERR, $this->client->response->body());

        $this->assertObjectHasAttribute('data', $object, $this->client->response->body());
    }


    /**
     * Add command test
     */
    public function testAdd()
    {
        $this->client->post('/command', [
        'command' => 'Another_test',
        'description' => 'fab command',
        'code' => 'commands code'
            ]);

        $this->assertSame(200, $this->client->response->status());
        $this->assertNotEmpty($this->client->response->body());

        $object = json_decode($this->client->response->body());
    //    fwrite(STDERR, $this->client->response->body());

        $this->assertObjectHasAttribute('data', $object);
        $this->assertObjectHasAttribute('id', $object);

        return $object->id;
    }

    /**
     * Edit command test
     * @depends testAdd
     * @group broken
     */
    public function testEdit($commandId)
    {
       $command = (new XiboCommand($this->getEntityProvider()))->getById($commandId);

        $this->client->put('/command/' . $commandId, [
        'command' => 'Another_test',
        'description' => 'EDITED',
        'code' => 'commands code'
            ], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']);

        $this->assertSame(200, $this->client->response->status());
        $this->assertNotEmpty($this->client->response->body());

        $object = json_decode($this->client->response->body());
    //    fwrite(STDERR, $this->client->response->body());

        $this->assertObjectHasAttribute('data', $object);

        return $commandId;
    }

    /**
     * Delete Added command
     * @depends testEdit
     */
    public function testDelete($commandId)
    {
        $this->client->delete('/command/' . $commandId);

        $this->assertSame(200, $this->client->response->status(), $this->client->response->body());
    }

}
