<?php

namespace Sentry\Laravel\Tests\Features;

use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Sentry\Laravel\Tests\TestCase;

class HttpClientIntegrationTest extends TestCase
{
    public function testHttpClientBreadcrumbIsRecordedForResponseReceivedEvent(): void
    {
        $this->skipIfEventClassNotAvailable();

        $this->dispatchLaravelEvent(new ResponseReceived(
            new Request(new PsrRequest('GET', 'https://example.com', [], 'request')),
            new Response(new PsrResponse(200, [], 'response'))
        ));

        $this->assertCount(1, $this->getCurrentSentryBreadcrumbs());

        $metadata = $this->getLastSentryBreadcrumb()->getMetadata();

        $this->assertEquals('GET', $metadata['http.request.method']);
        $this->assertEquals('https://example.com', $metadata['url']);
        $this->assertEquals(200, $metadata['http.response.status_code']);
        $this->assertEquals(7, $metadata['http.request.body.size']);
        $this->assertEquals(8, $metadata['http.response.body.size']);
    }

    public function testHttpClientBreadcrumbDoesntConsumeBodyStream(): void
    {
        $this->skipIfEventClassNotAvailable();

        $this->dispatchLaravelEvent(new ResponseReceived(
            $request = new Request(new PsrRequest('GET', 'https://example.com', [], 'request')),
            $response = new Response(new PsrResponse(200, [], 'response'))
        ));

        $this->assertCount(1, $this->getCurrentSentryBreadcrumbs());

        $this->assertEquals('request', $request->toPsrRequest()->getBody()->getContents());
        $this->assertEquals('response', $response->toPsrResponse()->getBody()->getContents());
    }

    private function skipIfEventClassNotAvailable(): void
    {
        if (class_exists(ResponseReceived::class)) {
            return;
        }

        $this->markTestSkipped('The Laravel HTTP client events are only available in Laravel 8.0+');
    }

    public function testHttpClientMiddelwareIsRegistered(): void
    {
        $this->skipIfGlobalMiddlewareIsNotAvailable();

        $transaction = $this->startTransaction();

        $client = Http::fake();

        $client->get('https://example.com');

        /** @var \Sentry\Tracing\Span $span */
        $span = last($transaction->getSpanRecorder()->getSpans());

        $this->assertEquals('http.client', $span->getOp());
        $this->assertEquals('GET https://example.com', $span->getDescription());
    }

    private function skipIfGlobalMiddlewareIsNotAvailable(): void
    {
        if (method_exists(Factory::class, 'globalMiddleware')) {
            return;
        }

        $this->markTestSkipped('The Laravel HTTP client global middleware is only available in Laravel 10.31+');
    }
}
