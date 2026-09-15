<?php

namespace Sentry\Laravel\Tests\Features;

use GuzzleHttp\Psr7\NoSeekStream;
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

class HttpClientBodyCollectionTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(ResponseReceived::class)) {
            $this->markTestSkipped('The Laravel HTTP client events are only available in Laravel 8.0+');
        }

        parent::setUp();
    }

    public function testRequestBodyIsCollectedOnHttpClientSpan(): void
    {
        $transaction = $this->configureAndStartTransaction();

        $this->dispatchExchange();

        $this->assertSame(
            ['name' => 'Alice', 'password' => '[Filtered]'],
            $this->lastSpan($transaction)->getData()['http.request.body.data']
        );
    }

    public function testResponseBodyIsCollectedOnHttpClientSpan(): void
    {
        $transaction = $this->configureAndStartTransaction();

        $this->dispatchExchange();

        $this->assertSame(
            ['ok' => true, 'token' => '[Filtered]'],
            $this->lastSpan($transaction)->getData()['http.response.body.data']
        );
    }

    public function testRequestBodyIsCollectedOnHttpClientBreadcrumb(): void
    {
        $this->configure();

        $this->dispatchExchange();

        $this->assertSame(
            ['name' => 'Alice', 'password' => '[Filtered]'],
            $this->getLastSentryBreadcrumb()->getMetadata()['http.request.body.data']
        );
    }

    public function testResponseBodyIsCollectedOnHttpClientBreadcrumb(): void
    {
        $this->configure();

        $this->dispatchExchange();

        $this->assertSame(
            ['ok' => true, 'token' => '[Filtered]'],
            $this->getLastSentryBreadcrumb()->getMetadata()['http.response.body.data']
        );
    }

    public function testConnectionFailureCollectsRequestBody(): void
    {
        $this->configure();
        list($request) = $this->httpExchange();

        $this->dispatchLaravelEvent(new ConnectionFailed($request, new ConnectionException('failed')));

        $this->assertSame(
            ['name' => 'Alice', 'password' => '[Filtered]'],
            $this->getLastSentryBreadcrumb()->getMetadata()['http.request.body.data']
        );
    }

    public function testBodyStreamsRemainAtTheirOriginalPositions(): void
    {
        $this->configureAndStartTransaction();
        list($request, $response) = $this->httpExchange();
        $requestBody = $request->toPsrRequest()->getBody();
        $responseBody = $response->toPsrResponse()->getBody();
        $requestBody->seek(5);
        $responseBody->seek(4);

        $this->dispatchLaravelEvent(new RequestSending($request));
        $this->dispatchLaravelEvent(new ResponseReceived($request, $response));

        $this->assertSame(5, $requestBody->tell());
        $this->assertSame(4, $responseBody->tell());
        $this->assertSame('e":"Alice","password":"secret"}', $requestBody->getContents());
        $this->assertSame('":true,"token":"secret"}', $responseBody->getContents());
    }

    public function testNonSeekableBodiesAreNotCollected(): void
    {
        $transaction = $this->configureAndStartTransaction();
        list($request, $response) = $this->httpExchange();
        $request = new Request($request->toPsrRequest()->withBody(new NoSeekStream($request->toPsrRequest()->getBody())));
        $response = new Response($response->toPsrResponse()->withBody(new NoSeekStream($response->toPsrResponse()->getBody())));

        $this->dispatchLaravelEvent(new RequestSending($request));
        $this->dispatchLaravelEvent(new ResponseReceived($request, $response));

        $data = $this->lastSpan($transaction)->getData();
        $this->assertArrayNotHasKey('http.request.body.data', $data);
        $this->assertArrayNotHasKey('http.response.body.data', $data);
    }

    public function testBodyCollectionCanBeTurnedOff(): void
    {
        $transaction = $this->configureAndStartTransaction([]);

        $this->dispatchExchange();

        $data = $this->lastSpan($transaction)->getData();
        $this->assertArrayNotHasKey('http.request.body.data', $data);
        $this->assertArrayNotHasKey('http.response.body.data', $data);
    }

    public function testKnownOversizedRequestIsNotRead(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.max_request_body_size' => 'small',
            'sentry.data_collection' => ['http_bodies' => ['outgoingRequest']],
        ]);
        $transaction = $this->startTransaction();
        $request = new Request(new PsrRequest(
            'POST',
            'https://example.com',
            ['Content-Type' => 'application/json'],
            str_repeat('a', 1001)
        ));

        $this->dispatchLaravelEvent(new RequestSending($request));

        $this->assertArrayNotHasKey('http.request.body.data', $this->lastSpan($transaction)->getData());
        $this->assertSame(0, $request->toPsrRequest()->getBody()->tell());
    }

    private function configure(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [
                'cookies' => ['mode' => 'off'],
                'http_headers' => ['mode' => 'off'],
            ],
        ]);
    }

    /** @param string[]|null $httpBodies */
    private function configureAndStartTransaction(?array $httpBodies = null): Transaction
    {
        $dataCollection = [
            'cookies' => ['mode' => 'off'],
            'http_headers' => ['mode' => 'off'],
        ];
        if ($httpBodies !== null) {
            $dataCollection['http_bodies'] = $httpBodies;
        }

        $this->resetApplicationWithConfig(['sentry.data_collection' => $dataCollection]);

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
            new Request(new PsrRequest(
                'POST',
                'https://example.com',
                ['Content-Type' => 'application/json'],
                '{"name":"Alice","password":"secret"}'
            )),
            new Response(new PsrResponse(
                200,
                ['Content-Type' => 'application/json'],
                '{"ok":true,"token":"secret"}'
            )),
        ];
    }

    private function lastSpan(Transaction $transaction): Span
    {
        $spans = $transaction->getSpanRecorder()->getSpans();

        return $spans[count($spans) - 1];
    }
}
