<?php

namespace Sentry\Laravel\Tests\Http;

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\TestCase;
use Sentry\Laravel\Http\ResponseSerializer;

class ResponseSerializerTest extends TestCase
{
    public function testCanSerializeResponse(): void
    {
        $psrResponse = new Psr7Response(
            200,
            ['Content-Type' => 'application/json'],
            '{"status":"ok"}'
        );

        $response = new Response($psrResponse);
        $serializer = new ResponseSerializer;

        $result = $serializer($response);

        $this->assertSame(200, $result['status']);
        $this->assertSame(['Content-Type' => ['application/json']], $result['headers']);
        $this->assertSame('{"status":"ok"}', $result['body']);
    }
}
