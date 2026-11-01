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
            ['Content-Type' => 'application/json', 'Authorization' => 'Bearer secret-token'],
            json_encode(['user' => 'Danial'])
        );
        $response = new Response($psrResponse);

        $serializer = new ResponseSerializer();
        $result = $serializer($response);

        $this->assertArrayHasKey('status', $result);
        $this->assertSame(200, $result['status']);
    }
}
