<?php

namespace Sentry\Laravel\Tests\Http;

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\TestCase;
use Sentry\Laravel\Http\ResponseSerializer;

class ResponseSerializerTest extends TestCase
{
    public function testSerializesBasicResponse(): void
    {
        $psrResponse = new Psr7Response(
            200,
            ['Content-Type' => ['application/json']],
            '{"message": "success"}'
        );
        $response = new Response($psrResponse);

        $serializer = new ResponseSerializer();
        $result = $serializer($response);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
        $this->assertEquals(200, $result['status']);
        $this->assertArrayHasKey('body', $result);
        $this->assertEquals('{"message": "success"}', $result['body']);
    }
}
