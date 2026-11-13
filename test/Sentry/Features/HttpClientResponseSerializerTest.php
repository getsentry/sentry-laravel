<?php

namespace Sentry\Laravel\Tests\Features;

use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\Response;
use Sentry\Laravel\Tests\TestCase;
use Sentry\Serializer\Serializer;

class HttpClientResponseSerializerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Response::class)) {
            $this->markTestSkipped('The Laravel HTTP client is only available in Laravel 7.0+');
        }

        parent::setUp();
    }

    public function testClassSerializerIsRegisteredForHttpClientResponse(): void
    {
        $classSerializers = $this->getSentryClientFromContainer()->getOptions()->getClassSerializers();

        $this->assertArrayHasKey(Response::class, $classSerializers);
        $this->assertIsCallable($classSerializers[Response::class]);
    }

    public function testHttpClientResponseIsSerializedWithoutPii(): void
    {
        $serialized = $this->serialize($this->makeResponse());

        $this->assertSame([
            'status' => 404,
            'reason' => 'Not Found',
            'url' => 'https://example.com/users/1',
            'content_type' => 'application/json',
        ], $serialized['data'] ?? null);
    }

    public function testHttpClientResponseIsSerializedWithPii(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
        ]);

        $serialized = $this->serialize($this->makeResponse());

        $this->assertSame(404, $serialized['data']['status']);
        $this->assertSame('Not Found', $serialized['data']['reason']);
        $this->assertSame('https://user:secret@example.com/users/1?token=secret#fragment', $serialized['data']['url']);
        $this->assertSame('application/json', $serialized['data']['content_type']);
        $this->assertSame('application/json', $serialized['data']['headers']['Content-Type']);
        $this->assertSame('foo=bar', $serialized['data']['headers']['Set-Cookie']);
    }

    public function testHttpClientResponseWithoutTransferStatsIsSerialized(): void
    {
        $serialized = $this->serialize(new Response(new PsrResponse(200, [], 'body')));

        $this->assertSame([
            'status' => 200,
            'reason' => 'OK',
        ], $serialized['data'] ?? null);
    }

    public function testUserConfiguredClassSerializerTakesPrecedence(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.class_serializers' => [
                Response::class => static function (Response $response): array {
                    return ['custom' => $response->status()];
                },
            ],
        ]);

        $serialized = $this->serialize($this->makeResponse());

        $this->assertSame(['custom' => 404], $serialized['data'] ?? null);
    }

    public function testUserConfiguredClassSerializerForInterfaceTakesPrecedence(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.class_serializers' => [
                \ArrayAccess::class => static function (\ArrayAccess $object): array {
                    return ['custom' => true];
                },
            ],
        ]);

        $serialized = $this->serialize($this->makeResponse());

        $this->assertSame(['custom' => true], $serialized['data'] ?? null);
    }

    private function makeResponse(): Response
    {
        $response = new Response(new PsrResponse(404, [
            'Content-Type' => 'application/json',
            'Set-Cookie' => 'foo=bar',
        ], '{"message":"not found"}'));

        $response->transferStats = new TransferStats(
            new PsrRequest('GET', 'https://user:secret@example.com/users/1?token=secret#fragment')
        );

        return $response;
    }

    private function serialize(Response $response)
    {
        $serializer = new Serializer($this->getSentryClientFromContainer()->getOptions());

        return $serializer->serialize($response);
    }
}
