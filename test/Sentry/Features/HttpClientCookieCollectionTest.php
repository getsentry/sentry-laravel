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
use Sentry\Tracing\Span;
use Sentry\Tracing\Transaction;

class HttpClientCookieCollectionTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(ResponseReceived::class)) {
            $this->markTestSkipped('The Laravel HTTP client events are only available in Laravel 8.0+');
        }

        parent::setUp();
    }

    public function testRequestCookiesAreCollectedOnHttpClientSpans(): void
    {
        $transaction = $this->configureAndStartTransaction([]);

        $this->dispatchExchange();

        $this->assertSame('dark', $this->lastSpan($transaction)->getData()['http.request.header.cookie.theme']);
    }

    public function testResponseCookiesAreCollectedOnHttpClientSpans(): void
    {
        $transaction = $this->configureAndStartTransaction([]);

        $this->dispatchExchange();

        $this->assertSame('light', $this->lastSpan($transaction)->getData()['http.response.header.set_cookie.theme']);
    }

    public function testRequestCookiesAreCollectedOnHttpClientBreadcrumbs(): void
    {
        $this->configure([]);

        $this->dispatchExchange();

        $this->assertSame('dark', $this->getLastSentryBreadcrumb()->getMetadata()['http.request.header.cookie.theme']);
    }

    public function testResponseCookiesAreCollectedOnHttpClientBreadcrumbs(): void
    {
        $this->configure([]);

        $this->dispatchExchange();

        $this->assertSame('light', $this->getLastSentryBreadcrumb()->getMetadata()['http.response.header.set_cookie.theme']);
    }

    public function testSensitiveRequestCookiesAreFiltered(): void
    {
        $transaction = $this->configureAndStartTransaction([]);

        $this->dispatchExchange();

        $this->assertSame('[Filtered]', $this->lastSpan($transaction)->getData()['http.request.header.cookie.session_id']);
    }

    public function testSensitiveResponseCookiesAreFiltered(): void
    {
        $transaction = $this->configureAndStartTransaction([]);

        $this->dispatchExchange();

        $this->assertSame('[Filtered]', $this->lastSpan($transaction)->getData()['http.response.header.set_cookie.session_id']);
    }

    public function testRepeatedResponseCookieValuesArePreserved(): void
    {
        $transaction = $this->configureAndStartTransaction([]);
        list($request) = $this->httpExchange();
        $response = new Response(new PsrResponse(200, [
            'Set-Cookie' => ['theme=light; Path=/', 'theme=dark; Path=/admin'],
        ]));

        $this->dispatchLaravelEvent(new RequestSending($request));
        $this->dispatchLaravelEvent(new ResponseReceived($request, $response));

        $this->assertSame(['light', 'dark'], $this->lastSpan($transaction)->getData()['http.response.header.set_cookie.theme']);
    }

    public function testCookieCollectionCanBeTurnedOff(): void
    {
        $transaction = $this->configureAndStartTransaction(['mode' => 'off']);

        $this->dispatchExchange();

        $this->assertSame([], $this->cookieData($this->lastSpan($transaction)->getData()));
    }

    public function testCookieAllowListFiltersUnlistedCookies(): void
    {
        $transaction = $this->configureAndStartTransaction([
            'mode' => 'allowList',
            'terms' => ['theme'],
        ]);

        $this->dispatchExchange();

        $this->assertSame('[Filtered]', $this->lastSpan($transaction)->getData()['http.request.header.cookie.locale']);
    }

    public function testConnectionFailureCollectsRequestCookies(): void
    {
        $this->configure([]);
        list($request) = $this->httpExchange();

        $this->dispatchLaravelEvent(new ConnectionFailed($request, new ConnectionException('failed')));

        $this->assertSame('dark', $this->getLastSentryBreadcrumb()->getMetadata()['http.request.header.cookie.theme']);
    }

    public function testExplicitResponseCookieDataIsPreserved(): void
    {
        $transaction = $this->configureAndStartTransaction([]);
        list($request, $response) = $this->httpExchange();
        $this->dispatchLaravelEvent(new RequestSending($request));
        $span = $this->lastSpan($transaction);
        $span->setData(['http.response.header.set_cookie.theme' => null]);

        $this->dispatchLaravelEvent(new ResponseReceived($request, $response));

        $this->assertNull($span->getData()['http.response.header.set_cookie.theme']);
    }

    /** @param array<string, mixed> $cookies */
    private function configure(array $cookies): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [
                'cookies' => $cookies,
                'http_headers' => ['mode' => 'off'],
            ],
        ]);
    }

    /** @param array<string, mixed> $cookies */
    private function configureAndStartTransaction(array $cookies): Transaction
    {
        $this->configure($cookies);

        return $this->startTransaction();
    }

    private function dispatchExchange(): void
    {
        list($request, $response) = $this->httpExchange();

        $this->dispatchLaravelEvent(new RequestSending($request));
        $this->dispatchLaravelEvent(new ResponseReceived($request, $response));
    }

    /** @return array{Request, Response} */
    private function httpExchange(): array
    {
        return [
            new Request(new PsrRequest('GET', 'https://example.com', [
                'Cookie' => 'theme=dark; locale=en; session_id=request-secret',
            ])),
            new Response(new PsrResponse(200, [
                'Set-Cookie' => [
                    'theme=light; Path=/',
                    'session_id=response-secret; HttpOnly',
                ],
            ])),
        ];
    }

    private function lastSpan(Transaction $transaction): Span
    {
        $spans = $transaction->getSpanRecorder()->getSpans();

        return $spans[count($spans) - 1];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function cookieData(array $data): array
    {
        return array_filter($data, static function ($key): bool {
            return strpos($key, '.cookie.') !== false || strpos($key, '.set_cookie.') !== false;
        }, \ARRAY_FILTER_USE_KEY);
    }
}
