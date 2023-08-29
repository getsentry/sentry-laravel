<?php

namespace Sentry\Laravel\Tests\Features;

use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Sentry\Laravel\Tests\TestCase;

class HttpClientIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(ResponseReceived::class)) {
            $this->markTestSkipped('The Laravel HTTP client events are only available in Laravel 8.0+');
        }

        parent::setUp();
    }

    public function testHttpClientBreadcrumbIsRecordedForResponseReceivedEvent(): void
    {
        $this->dispatchLaravelEvent(new ResponseReceived(
            new Request(new PsrRequest('GET', 'https://example.com', [], 'request')),
            new Response(new PsrResponse(200, [], 'response'))
        ));

        $this->assertCount(1, $this->getCurrentBreadcrumbs());

        $metadata = $this->getLastBreadcrumb()->getMetadata();

        $this->assertEquals('GET', $metadata['http.request.method']);
        $this->assertEquals('https://example.com', $metadata['url']);
        $this->assertEquals(200, $metadata['http.response.status_code']);
        $this->assertEquals(7, $metadata['http.request.body.size']);
        $this->assertEquals(8, $metadata['http.response.body.size']);
    }

    public function testHttpClientBreadcrumbDoesntConsumeBodyStream(): void
    {
        $this->dispatchLaravelEvent(new ResponseReceived(
            $request = new Request(new PsrRequest('GET', 'https://example.com', [], 'request')),
            $response = new Response(new PsrResponse(200, [], 'response'))
        ));

        $this->assertCount(1, $this->getCurrentBreadcrumbs());

        $this->assertEquals('request', $request->toPsrRequest()->getBody()->getContents());
        $this->assertEquals('response', $response->toPsrResponse()->getBody()->getContents());
    }
}
