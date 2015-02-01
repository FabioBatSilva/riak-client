<?php

namespace Riak\Client\Core\Transport\Proto;

use DrSlump\Protobuf\Message;
use DrSlump\Protobuf\Protobuf;
use Riak\Client\ProtoBuf\RiakMessageCodes;
use Riak\Client\Core\Transport\RiakTransportException;

/**
 * RPB socket connection
 *
 * @author Fabio B. Silva <fabio.bat.silva@gmail.com>
 */
class ProtoClient
{
    /**
     * @var resource
     */
    private $resource;

    /**
     * @var string
     */
    private $host;

    /**
     * @var integer
     */
    private $port;

    /**
     * @var integer
     */
    private $timeout;

    /**
     * Mapping of message code to PB response class names
     *
     * @var array
     */
    private static $respClassMap = [
        RiakMessageCodes::DT_FETCH_RESP             => 'Riak\Client\ProtoBuf\DtFetchResp',
        RiakMessageCodes::DT_UPDATE_RESP            => 'Riak\Client\ProtoBuf\DtUpdateResp',
        RiakMessageCodes::ERROR_RESP                => 'Riak\Client\ProtoBuf\RpbErrorResp',
        RiakMessageCodes::GET_BUCKET_RESP           => 'Riak\Client\ProtoBuf\RpbGetBucketResp',
        RiakMessageCodes::GET_RESP                  => 'Riak\Client\ProtoBuf\RpbGetResp',
        RiakMessageCodes::GET_SERVER_INFO_RESP      => 'Riak\Client\ProtoBuf\RpbGetServerInfoResp',
        RiakMessageCodes::LIST_BUCKETS_RESP         => 'Riak\Client\ProtoBuf\RpbListBucketsResp',
        RiakMessageCodes::LIST_KEYS_RESP            => 'Riak\Client\ProtoBuf\RpbListKeysResp',
        RiakMessageCodes::PUT_RESP                  => 'Riak\Client\ProtoBuf\RpbPutResp',
        RiakMessageCodes::INDEX_RESP                => 'Riak\Client\ProtoBuf\RpbIndexResp',
        RiakMessageCodes::SEARCH_QUERY_RESP         => 'Riak\Client\ProtoBuf\RpbSearchQueryResp',
        RiakMessageCodes::YOKOZUNA_INDEX_GET_RESP   => 'Riak\Client\ProtoBuf\RpbYokozunaIndexGetResp',
        RiakMessageCodes::YOKOZUNA_SCHEMA_GET_RESP  => 'Riak\Client\ProtoBuf\RpbYokozunaSchemaGetResp'
    ];

    /**
     * @param string $host
     * @param string $port
     */
    public function __construct($host, $port)
    {
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * @return integer
     */
    public function getTimeout()
    {
        return $this->timeout;
    }

    /**
     * @param integer $timeout
     */
    public function setTimeout($timeout)
    {
        $this->timeout = $timeout;
    }

    /**
     * Send a Protobuf message and receive the response
     *
     * @param \DrSlump\Protobuf\Message $message
     * @param integer                   $messageCode
     * @param integer                   $expectedResponseCode
     *
     * @return \DrSlump\Protobuf\Message
     */
    public function send(Message $message, $messageCode, $expectedResponseCode)
    {
        $payload  = $this->encodeMessage($message, $messageCode);
        $class    = $this->classForCode($expectedResponseCode);
        $response = $this->sendData($payload);
        $respCode = $response[0];
        $respBody = $response[1];

        if ($respCode != $expectedResponseCode) {
            $this->throwResponseException($respCode, $respBody);
        }

        if ($class == null) {
            return;
        }

        return Protobuf::decode($class, $respBody);
    }

    /**
     * Send a Protobuf message but does not receive the response
     *
     * @param \DrSlump\Protobuf\Message $message
     * @param integer                   $messageCode
     */
    public function emit(Message $message, $messageCode)
    {
        $this->sendPayload($this->encodeMessage($message, $messageCode));
    }

    /**
     * Receive a protobuf reponse message
     *
     * @param integer $messageCode
     *
     * @return \DrSlump\Protobuf\Message
     */
    public function receiveMessage($messageCode)
    {
        $class    = $this->classForCode($messageCode);
        $response = $this->receive();
        $respCode = $response[0];
        $respBody = $response[1];

        if ($respCode != $messageCode) {
            $this->throwResponseException($respCode, $respBody);
        }

        if ($class == null) {
            throw new \InvalidArgumentException("Invalid response class for message code : $messageCode");
        }

        return Protobuf::decode($class, $respBody);
    }

    /**
     * @param string $code
     *
     * @return string
     */
    protected function classForCode($code)
    {
        if (isset(self::$respClassMap[$code])) {
            return self::$respClassMap[$code];
        }

        return null;
    }

    /**
     * @param integer $actualCode
     * @param string  $respBody
     *
     * @throws \Riak\Client\Core\Transport\RiakTransportException
     */
    protected function throwResponseException($actualCode, $respBody)
    {
        $this->resource = null;

        $exceptionCode    = $actualCode;
        $exceptionMessage = "Unexpected rpb response code: " . $actualCode;

        if ($actualCode === RiakMessageCodes::ERROR_RESP) {
            $errorClass   = self::$respClassMap[$actualCode];
            $errorMessage = Protobuf::decode($errorClass, $respBody);

            if ($errorMessage->hasErrmsg()) {
                $exceptionMessage  = $errorMessage->getErrmsg();
            }

            if ($errorMessage->hasErrcode()) {
                $exceptionCode = $errorMessage->getErrcode();
            }
        }

        throw new RiakTransportException($exceptionMessage, $exceptionCode);
    }

    /**
     * @return resource
     */
    protected function getConnection()
    {
        if ($this->resource != null && is_resource($this->resource)) {
            return $this->resource;
        }

        $errno    = null;
        $errstr   = null;
        $uri      = sprintf('tcp://%s:%s', $this->host, $this->port);
        $resource = stream_socket_client($uri, $errno, $errstr);

        if ( ! is_resource($resource)) {
            throw new RiakTransportException(sprintf('Fail to connect to : %s [%s %s]', $uri, $errno, $errstr));
        }

        if ($this->timeout !== null) {
            stream_set_timeout($resource, $this->timeout);
        }

        return $this->resource = $resource;
    }

    /**
     * @param \DrSlump\Protobuf\Message $message
     * @param integer                   $code
     *
     * @return string
     */
    private function encodeMessage(Message $message, $code)
    {
        $encoded = Protobuf::encode($message);
        $lenght  = strlen($encoded);

        return pack("NC", 1 + $lenght, $code) . $encoded;
    }

    /**
     * @param string $payload
     *
     * @return array
     */
    private function sendData($payload)
    {
        $this->sendPayload($payload);

        return $this->receive();
    }

    /**
     * @param string $payload
     *
     * @return array
     */
    private function sendPayload($payload)
    {
        $resource = $this->getConnection();
        $lenght   = strlen($payload);
        $fwrite   = 0;

        for ($written = 0; $written < $lenght; $written += $fwrite) {
            $fwrite = fwrite($resource, substr($payload, $written));

            if ($fwrite === false) {
                throw new RiakTransportException('Failed to write message');
            }
        }
    }

    /**
     * @return array
     */
    private function receive()
    {
        $message  = '';
        $resource = $this->getConnection();
        $header   = fread($resource, 4);

        if ($header === false) {
            throw new RiakTransportException('Fail to read response headers');
        }

        if (strlen($header) !== 4) {
            throw new RiakTransportException('Short read on header, read ' . strlen($header) . ' bytes');
        }

        $unpackHeaders = array_values(unpack("N", $header));
        $length        = isset($unpackHeaders[0]) ? $unpackHeaders[0] : 0;

        while (strlen($message) !== $length) {

            $buffer = fread($resource, min(8192, $length - strlen($message)));

            if ( ! strlen($buffer) || $buffer === false) {
                throw new RiakTransportException('Fail to read socket response');
            }

            $message .= $buffer;
        }

        $messageBodyString = substr($message, 1);
        $messageCodeString = substr($message, 0, 1);
        list($messageCode) = array_values(unpack("C", $messageCodeString));

        return [$messageCode, $messageBodyString];
    }
}
