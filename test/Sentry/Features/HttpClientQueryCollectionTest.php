<?php

namespace Sentry\Laravel\Tests\Features;

use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Sentry\Laravel\Tests\TestCase;

class HttpClientQueryCollectionTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(ResponseReceived::class)) {
            $this->markTestSkipped('The Laravel HTTP client events are only available in Laravel 8.0+');
        }

        parent::setUp();
    }

    public function testConfiguredQueryPolicyFiltersSpanAndBreadcrumbWithoutChangingRequest(): void
    {
        $this->resetApplicationWithConfig(['sentry.data_collection' => []]);
        $transaction = $this->startTransaction();
        list($request, $response) = $this->exchange('https://example.com/path?tag=a&tag=b&%74oken=secret&q=a+b#fragment');
        $originalUri = (string) $request->toPsrRequest()->getUri();

        $this->dispatchLaravelEvent(new RequestSending($request));
        $this->dispatchLaravelEvent(new ResponseReceived($request, $response));

        $expected = 'tag=a&tag=b&%74oken=[Filtered]&q=a+b';
        $spans = $transaction->getSpanRecorder()->getSpans();
        $this->assertSame($expected, $spans[count($spans) - 1]->getData()['http.query']);
        $this->assertSame($expected, $this->getLastSentryBreadcrumb()->getMetadata()['http.query']);
        $this->assertSame($originalUri, (string) $request->toPsrRequest()->getUri());
        $this->assertSame('https://example.com/path', $this->getLastSentryBreadcrumb()->getMetadata()['url']);
    }

    public function testQueryPolicyCanBeDisabledOrRestricted(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => ['url_query_params' => ['mode' => 'off']],
        ]);
        list($request, $response) = $this->exchange('https://example.com/?visible=yes&private=no');
        $this->dispatchLaravelEvent(new ResponseReceived($request, $response));
        $this->assertArrayNotHasKey('http.query', $this->getLastSentryBreadcrumb()->getMetadata());

        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [
                'url_query_params' => ['mode' => 'allowList', 'terms' => ['visible']],
            ],
        ]);
        list($request, $response) = $this->exchange('https://example.com/?visible=yes&private=no');
        $this->dispatchLaravelEvent(new ResponseReceived($request, $response));
        $this->assertSame('visible=yes&private=[Filtered]', $this->getLastSentryBreadcrumb()->getMetadata()['http.query']);
    }

    public function testLegacyModePreservesRawAndEmptyQueryAttributes(): void
    {
        $this->resetApplicationWithConfig(['sentry.data_collection' => null]);
        list($request, $response) = $this->exchange('https://example.com/?token=secret&q=a%20b');
        $this->dispatchLaravelEvent(new ResponseReceived($request, $response));
        $this->assertSame('token=secret&q=a%20b', $this->getLastSentryBreadcrumb()->getMetadata()['http.query']);

        list($request, $response) = $this->exchange('https://example.com/path');
        $this->dispatchLaravelEvent(new ResponseReceived($request, $response));
        $this->assertSame('', $this->getLastSentryBreadcrumb()->getMetadata()['http.query']);
    }

    public function testConfiguredEmptyQueryIsOmitted(): void
    {
        $this->resetApplicationWithConfig(['sentry.data_collection' => []]);
        list($request, $response) = $this->exchange('https://example.com/path');

        $this->dispatchLaravelEvent(new ResponseReceived($request, $response));

        $this->assertArrayNotHasKey('http.query', $this->getLastSentryBreadcrumb()->getMetadata());
    }

    public function testFailureBreadcrumbCollectsQueryWithoutTracing(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
            'sentry.tracing.http_client_requests' => false,
        ]);
        list($request) = $this->exchange('https://example.com/?api%5Ftoken=secret&page=2');

        $this->dispatchLaravelEvent(new ConnectionFailed($request, new ConnectionException('failed')));

        $this->assertSame('api%5Ftoken=[Filtered]&page=2', $this->getLastSentryBreadcrumb()->getMetadata()['http.query']);
    }

    /** @return array{Request, Response} */
    private function exchange(string $uri): array
    {
        return [
            new Request(new PsrRequest('GET', $uri)),
            new Response(new PsrResponse(200)),
        ];
    }
}
