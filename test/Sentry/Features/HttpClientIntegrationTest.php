<?php

namespace Sentry\Laravel\Tests\Features;

use App\HttpClientOriginFixture;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Sentry\Laravel\Tests\TestCase;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;

require_once __DIR__ . '/../../stubs/app/HttpClientOriginFixture.php';

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
        $this->dispatchLaravelEvent(new ResponseReceived(
            $request = new Request(new PsrRequest('GET', 'https://example.com', [], 'request')),
            $response = new Response(new PsrResponse(200, [], 'response'))
        ));

        $this->assertCount(1, $this->getCurrentSentryBreadcrumbs());

        $this->assertEquals('request', $request->toPsrRequest()->getBody()->getContents());
        $this->assertEquals('response', $response->toPsrResponse()->getBody()->getContents());
    }

    public function testHttpClientBreadcrumbIsNotRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.http_client_requests' => false,
        ]);

        $this->dispatchLaravelEvent(new ResponseReceived(
            new Request(new PsrRequest('GET', 'https://example.com', [], 'request')),
            new Response(new PsrResponse(200, [], 'response'))
        ));

        $this->assertEmpty($this->getCurrentSentryBreadcrumbs());
    }

    public function testHttpClientSpanIsRecorded(): void
    {
        $transaction = $this->startTransaction();

        $client = Http::fake();

        $client->get('https://example.com');

        /** @var \Sentry\Tracing\Span $span */
        $span = last($transaction->getSpanRecorder()->getSpans());

        $this->assertEquals('http.client', $span->getOp());
        $this->assertEquals('GET https://example.com', $span->getDescription());
    }

    public function testHttpClientSpanIsRecordedWithCorrectResult(): void
    {
        $transaction = $this->startTransaction();

        $client = Http::fake([
            'example.com/success' => Http::response('OK'),
            'example.com/error' => Http::response('Internal Server Error', 500),
        ]);

        $client->get('https://example.com/success');

        /** @var \Sentry\Tracing\Span $span */
        $span = last($transaction->getSpanRecorder()->getSpans());

        $this->assertEquals('http.client', $span->getOp());
        $this->assertEquals(SpanStatus::ok(), $span->getStatus());

        $client->get('https://example.com/error');

        /** @var \Sentry\Tracing\Span $span */
        $span = last($transaction->getSpanRecorder()->getSpans());

        $this->assertEquals('http.client', $span->getOp());
        $this->assertEquals(SpanStatus::internalError(), $span->getStatus());
    }

    public function testHttpClientSpanIsNotRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.http_client_requests' => false,
        ]);

        $transaction = $this->startTransaction();

        $client = Http::fake();

        $client->get('https://example.com');

        /** @var \Sentry\Tracing\Span $span */
        $span = last($transaction->getSpanRecorder()->getSpans());

        $this->assertNotEquals('http.client', $span->getOp());
    }

    public function testHttpClientRequestTracingHeadersAreAttached(): void
    {
        if (!method_exists(Factory::class, 'globalRequestMiddleware')) {
            $this->markTestSkipped('The `globalRequestMiddleware` functionality we rely on was introduced in Laravel 10.14');
        }

        $this->resetApplicationWithConfig([
            'sentry.trace_propagation_targets' => ['example.com'],
        ]);

        $client = Http::fake();

        $client->get('https://example.com');

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('baggage') && $request->hasHeader('sentry-trace');
        });

        $client->get('https://no-headers.example.com');

        Http::assertSent(function (Request $request) {
            return !$request->hasHeader('baggage') && !$request->hasHeader('sentry-trace');
        });
    }

    public function testHttpClientOriginIsResolvedWhenEnabled(): void
    {
        $this->resetApplicationWithOriginConfig([
            'sentry.tracing.http_client_requests_origin' => true,
            'sentry.tracing.http_client_requests_origin_threshold_ms' => 0,
        ]);

        $transaction = $this->startTransaction();

        Http::fake();

        (new HttpClientOriginFixture)->get('https://example.com');

        $this->assertSpanHasFixtureOrigin(last($transaction->getSpanRecorder()->getSpans()));
    }

    public function testHttpClientOriginIsNotResolvedWhenDisabled(): void
    {
        $this->resetApplicationWithOriginConfig([
            'sentry.tracing.http_client_requests_origin' => false,
            'sentry.tracing.http_client_requests_origin_threshold_ms' => 0,
        ]);

        $transaction = $this->startTransaction();

        Http::fake();

        (new HttpClientOriginFixture)->get('https://example.com');

        $this->assertSpanHasNoOrigin(last($transaction->getSpanRecorder()->getSpans()));
    }

    public function testHttpClientOriginIsResolvedWhenOverThreshold(): void
    {
        $this->resetApplicationWithOriginConfig([
            'sentry.tracing.http_client_requests_origin' => true,
            'sentry.tracing.http_client_requests_origin_threshold_ms' => 10,
        ]);

        $transaction = $this->startTransaction();

        Http::fake([
            'slow.example.com' => function () {
                usleep(20000); // 20ms delay
                return Http::response('OK');
            },
        ]);

        (new HttpClientOriginFixture)->get('https://slow.example.com');

        $this->assertSpanHasFixtureOrigin(last($transaction->getSpanRecorder()->getSpans()));
    }

    public function testHttpClientOriginIsNotResolvedWhenUnderThreshold(): void
    {
        $this->resetApplicationWithOriginConfig([
            'sentry.tracing.http_client_requests_origin' => true,
            'sentry.tracing.http_client_requests_origin_threshold_ms' => 1000,
        ]);

        $transaction = $this->startTransaction();

        Http::fake();

        (new HttpClientOriginFixture)->get('https://example.com');

        $this->assertSpanHasNoOrigin(last($transaction->getSpanRecorder()->getSpans()));
    }

    public function testHttpClientOriginIsResolvedWhenConnectionFailed(): void
    {
        $this->resetApplicationWithOriginConfig([
            'sentry.tracing.http_client_requests_origin' => true,
            'sentry.tracing.http_client_requests_origin_threshold_ms' => 0,
        ]);

        $transaction = $this->startTransaction();

        Http::fake(function () {
            throw new ConnectException('Connection timed out', new PsrRequest('GET', 'https://example.com'));
        });

        try {
            (new HttpClientOriginFixture)->get('https://example.com');

            $this->fail('Expected the HTTP client request to fail.');
        } catch (ConnectionException $e) {
            // We only care about the span that was recorded for the failed request
        }

        /** @var \Sentry\Tracing\Span $span */
        $span = last($transaction->getSpanRecorder()->getSpans());

        $this->assertEquals(SpanStatus::internalError(), $span->getStatus());
        $this->assertSpanHasFixtureOrigin($span);
    }

    public function testHttpClientOriginThresholdAcceptsNumericStrings(): void
    {
        $this->resetApplicationWithOriginConfig([
            'sentry.tracing.http_client_requests_origin_threshold_ms' => '0',
        ]);

        $transaction = $this->startTransaction();

        Http::fake();

        (new HttpClientOriginFixture)->get('https://example.com');

        $this->assertSpanHasFixtureOrigin(last($transaction->getSpanRecorder()->getSpans()));
    }

    public function testHttpClientOriginThresholdFallsBackToDefaultWhenNotNumeric(): void
    {
        // An empty `SENTRY_TRACE_HTTP_CLIENT_REQUESTS_ORIGIN_THRESHOLD_MS=` in the `.env` results in an empty string
        $this->resetApplicationWithOriginConfig([
            'sentry.tracing.http_client_requests_origin_threshold_ms' => '',
        ]);

        $transaction = $this->startTransaction();

        Http::fake();

        (new HttpClientOriginFixture)->get('https://example.com');

        // The faked request is faster than the default threshold of 250ms
        $this->assertSpanHasNoOrigin(last($transaction->getSpanRecorder()->getSpans()));
    }

    private function resetApplicationWithOriginConfig(array $config): void
    {
        $this->resetApplicationWithConfig(array_merge([
            // The service provider excludes `base_path('vendor')` from in-app frames, but our base path is the
            // Testbench skeleton, so exclude the vendor directory of this package to match a real application
            'sentry.in_app_exclude' => [dirname(__DIR__, 3) . '/vendor'],
        ], $config));
    }

    private function assertSpanHasFixtureOrigin(Span $span): void
    {
        $method = new ReflectionMethod(HttpClientOriginFixture::class, 'get');

        $data = $span->getData();

        $this->assertSame($method->getFileName(), $data['code.filepath'] ?? null);
        // The request is made on the first line of the method body
        $this->assertSame($method->getStartLine() + 2, $data['code.lineno'] ?? null);
        $this->assertSame(HttpClientOriginFixture::class . '::get', $data['code.function'] ?? null);
    }

    private function assertSpanHasNoOrigin(Span $span): void
    {
        $this->assertArrayNotHasKey('code.filepath', $span->getData());
        $this->assertArrayNotHasKey('code.lineno', $span->getData());
        $this->assertArrayNotHasKey('code.function', $span->getData());
    }
}
