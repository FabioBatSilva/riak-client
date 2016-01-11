<?php

namespace RiakClientTest\Core\Transport\Proto\Kv;

use RiakClientTest\TestCase;
use Riak\Client\ProtoBuf\RiakMessageCodes;
use Riak\Client\Core\Transport\Proto\Kv\ProtoDelete;
use Riak\Client\Core\Message\Kv\DeleteRequest;


class ProtoDeleteTest extends TestCase
{
    /**
     * @var \Riak\Client\Core\Transport\Proto\ProtoClient
     */
    private $client;

    /**
     * @var \Riak\Client\Core\Transport\Proto\Kv\ProtoDelete
     */
    private $instance;

    protected function setUp()
    {
        parent::setUp();

        $this->client   = $this->getMock('Riak\Client\Core\Transport\Proto\ProtoClient', [], [], '', false);
        $this->instance = new ProtoDelete($this->client);
    }

    public function testCreateDeleteProtoMessage()
    {
        $deleteRequest = new DeleteRequest();

        $deleteRequest->vClock = 'vclock-hash';
        $deleteRequest->bucket = 'test_bucket';
        $deleteRequest->type   = 'default';
        $deleteRequest->key    = '1';

        $deleteRequest->r  = 1;
        $deleteRequest->pr = 2;
        $deleteRequest->rw = 3;
        $deleteRequest->w  = 3;
        $deleteRequest->dw = 2;
        $deleteRequest->pw = 1;

        $result = $this->invokeMethod($this->instance, 'createRpbMessage', [$deleteRequest]);

        $this->assertInstanceOf('Riak\Client\ProtoBuf\RpbDelReq', $result);
        $this->assertEquals('vclock-hash', $result->getVclock());
        $this->assertEquals('test_bucket', $result->getBucket());
        $this->assertEquals('default', $result->getType());
        $this->assertEquals('1', $result->getKey());

        $this->assertEquals('1', $result->getR());
        $this->assertEquals('2', $result->getPr());
        $this->assertEquals('3', $result->getRw());
        $this->assertEquals('3', $result->getW());
        $this->assertEquals('2', $result->getDw());
        $this->assertEquals('1', $result->getPw());
    }

    public function testSendDeleteRequest()
    {
        $request  = new DeleteRequest();
        $callback = function($subject) {

            $this->assertInstanceOf('Riak\Client\ProtoBuf\RpbDelReq', $subject);
            $this->assertEquals('test_bucket', $subject->getBucket());
            $this->assertEquals('default', $subject->getType());
            $this->assertEquals('1', $subject->getKey());

            return true;
        };

        $this->client->expects($this->once())
            ->method('send')
            ->with($this->callback($callback), $this->equalTo(RiakMessageCodes::DEL_REQ), $this->equalTo(RiakMessageCodes::DEL_RESP));

        $request->bucket = 'test_bucket';
        $request->type   = 'default';
        $request->key    = '1';

        $this->assertInstanceOf('Riak\Client\Core\Message\Kv\DeleteResponse', $this->instance->send($request));
    }
}