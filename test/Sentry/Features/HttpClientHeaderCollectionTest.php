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
use ReflectionProperty;
use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\Laravel\Features\HttpClientIntegration;
use Sentry\Laravel\Integration as LaravelIntegration;
use Sentry\Laravel\Tests\TestCase;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\Scope;
use Sentry\Tracing\Span;

class HttpClientHeaderCollectionTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(ResponseReceived::class)) {
            $this->markTestSkipped('The Laravel HTTP client events are only available in Laravel 8.0+');
        }

        parent::setUp();
    }

    public function testRequestAndResponseHeadersAreCollectedOnSpanAndBreadcrumb(): void
    {
        $this->resetApplicationWithConfig(['sentry.data_collection' => []]);
        $transaction = $this->startTransaction();
        list($request, $response) = $this->httpExchange();

        $this->dispatchLaravelEvent(new RequestSending($request));
        $this->dispatchLaravelEvent(new ResponseReceived($request, $response));

        $span = $this->lastSpan($transaction->getSpanRecorder()->getSpans());
        $data = $span->getData();
        $this->assertSame(['request-id'], $data['http.request.header.x-request-id']);
        $this->assertSame(['[Filtered]'], $data['http.request.header.authorization']);
        $this->assertSame(['one', 'two'], $data['http.response.header.x-response-id']);
        $this->assertArrayNotHasKey('http.request.header.cookie', $data);
        $this->assertArrayNotHasKey('http.request.header.cookie.session', $data);
        $this->assertArrayNotHasKey('http.response.header.set-cookie', $data);
        $this->assertArrayNotHasKey('http.response.header.set_cookie.session', $data);
        $this->assertArrayNotHasKey('http.request.body.data', $data);
        $this->assertArrayNotHasKey('http.response.body.data', $data);

        $metadata = $this->getLastSentryBreadcrumb()->getMetadata();
        $this->assertSame(['request-id'], $metadata['http.request.header.x-request-id']);
        $this->assertSame(['one', 'two'], $metadata['http.response.header.x-response-id']);
        $this->assertSame('request body', $request->toPsrRequest()->getBody()->getContents());
        $this->assertSame('response body', $response->toPsrResponse()->getBody()->getContents());
    }

    public function testConnectionFailureBreadcrumbOnlyCollectsRequestHeaders(): void
    {
        $this->resetApplicationWithConfig(['sentry.data_collection' => []]);
        list($request) = $this->httpExchange();

        $this->dispatchLaravelEvent(new ConnectionFailed($request, new ConnectionException('failed')));

        $metadata = $this->getLastSentryBreadcrumb()->getMetadata();
        $this->assertSame(['request-id'], $metadata['http.request.header.x-request-id']);
        $this->assertArrayNotHasKey('http.response.header.x-response-id', $metadata);
    }

    public function testLegacyNullAndOffConfigurationDoNotAcquireHeaders(): void
    {
        list($request, $response) = $this->guardedHttpExchange();
        $this->dispatchLaravelEvent(new ResponseReceived($request, $response));
        $this->assertSame([], $this->headerData($this->getLastSentryBreadcrumb()->getMetadata()));
        $this->assertSame(0, $request->getHeadersCallCount());
        $this->assertSame(0, $response->getHeadersCallCount());

        $this->resetApplicationWithConfig(['sentry.data_collection' => null]);
        list($request, $response) = $this->guardedHttpExchange();
        $this->dispatchLaravelEvent(new ResponseReceived($request, $response));
        $this->assertSame([], $this->headerData($this->getLastSentryBreadcrumb()->getMetadata()));
        $this->assertSame(0, $request->getHeadersCallCount());
        $this->assertSame(0, $response->getHeadersCallCount());

        $this->resetApplicationWithConfig([
            'sentry.data_collection' => ['http_headers' => ['mode' => 'off']],
        ]);
        list($request, $response) = $this->guardedHttpExchange();
        $this->dispatchLaravelEvent(new ResponseReceived($request, $response));
        $this->assertSame([], $this->headerData($this->getLastSentryBreadcrumb()->getMetadata()));
        $this->assertSame(0, $request->getHeadersCallCount());
        $this->assertSame(0, $response->getHeadersCallCount());
    }

    public function testMissingClientDoesNotAcquireHeaders(): void
    {
        list($request, $response) = $this->guardedHttpExchange();
        $originalHub = SentrySdk::getCurrentHub();

        try {
            SentrySdk::setCurrentHub(new Hub());
            $integration = $this->app->make(HttpClientIntegration::class);
            $integration->handleResponseReceivedHandlerForBreadcrumb(new ResponseReceived($request, $response));
        } finally {
            SentrySdk::setCurrentHub($originalHub);
        }

        $this->assertSame(0, $request->getHeadersCallCount());
        $this->assertSame(0, $response->getHeadersCallCount());
    }

    public function testUnsampledTracingDoesNotAcquireHeaders(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
            'sentry.breadcrumbs.http_client_requests' => false,
        ]);
        $transaction = $this->startTransaction();
        $transaction->setSampled(false);
        list($request, $response) = $this->guardedHttpExchange();

        $this->dispatchLaravelEvent(new RequestSending($request));
        $this->dispatchLaravelEvent(new ResponseReceived($request, $response));

        $this->assertSame(0, $request->getHeadersCallCount());
        $this->assertSame(0, $response->getHeadersCallCount());
        $this->assertCount(1, $transaction->getSpanRecorder()->getSpans());
    }

    public function testDirectionsCanBeConfiguredIndependently(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [
                'http_headers' => [
                    'request' => ['mode' => 'off'],
                    'response' => ['mode' => 'allowList', 'terms' => ['x-response-id']],
                ],
            ],
        ]);
        list($request, $response) = $this->httpExchange();

        $this->dispatchLaravelEvent(new ResponseReceived($request, $response));

        $metadata = $this->getLastSentryBreadcrumb()->getMetadata();
        $this->assertArrayNotHasKey('http.request.header.x-request-id', $metadata);
        $this->assertSame(['one', 'two'], $metadata['http.response.header.x-response-id']);
        $this->assertSame(['[Filtered]'], $metadata['http.response.header.x-other']);
    }

    public function testBreadcrumbsCollectHeadersWithoutAnActiveSpan(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
            'sentry.tracing.http_client_requests' => false,
        ]);
        list($request, $response) = $this->httpExchange();

        $this->dispatchLaravelEvent(new ResponseReceived($request, $response));

        $this->assertSame(['request-id'], $this->getLastSentryBreadcrumb()->getMetadata()['http.request.header.x-request-id']);
    }

    public function testSuccessiveExchangesUseReplacementClientsAndIndependentBreadcrumbPolicies(): void
    {
        $integration = $this->app->make(HttpClientIntegration::class);
        $originalHub = SentrySdk::getCurrentHub();
        $requestScope = new Scope();
        $responseScope = new Scope();
        $requestHub = new Hub($this->clientWithDataCollection([
            'http_headers' => [
                'request' => ['mode' => 'allowList', 'terms' => ['x-request-id']],
                'response' => ['mode' => 'off'],
            ],
        ]), $requestScope);
        $responseHub = new Hub($this->clientWithDataCollection([
            'http_headers' => [
                'request' => ['mode' => 'off'],
                'response' => ['mode' => 'allowList', 'terms' => ['x-response-id']],
            ],
        ]), $responseScope);

        try {
            SentrySdk::setCurrentHub($requestHub);
            list($request, $response) = $this->httpExchange();
            $integration->handleResponseReceivedHandlerForBreadcrumb(new ResponseReceived($request, $response));

            SentrySdk::setCurrentHub($responseHub);
            list($request, $response) = $this->httpExchange();
            $integration->handleResponseReceivedHandlerForBreadcrumb(new ResponseReceived($request, $response));
        } finally {
            SentrySdk::setCurrentHub($originalHub);
        }

        $requestMetadata = $this->lastBreadcrumbFromScope($requestScope)->getMetadata();
        $this->assertSame(['request-id'], $requestMetadata['http.request.header.x-request-id']);
        $this->assertArrayNotHasKey('http.response.header.x-response-id', $requestMetadata);

        $responseMetadata = $this->lastBreadcrumbFromScope($responseScope)->getMetadata();
        $this->assertArrayNotHasKey('http.request.header.x-request-id', $responseMetadata);
        $this->assertSame(['one', 'two'], $responseMetadata['http.response.header.x-response-id']);
    }

    public function testExplicitSpanResponseHeaderAttributeIsPreserved(): void
    {
        $this->resetApplicationWithConfig(['sentry.data_collection' => []]);
        $transaction = $this->startTransaction();
        list($request, $response) = $this->httpExchange();

        $this->dispatchLaravelEvent(new RequestSending($request));
        $span = $this->lastSpan($transaction->getSpanRecorder()->getSpans());
        $span->setData(['http.response.header.x-response-id' => null]);
        $this->dispatchLaravelEvent(new ResponseReceived($request, $response));

        $this->assertNull($span->getData()['http.response.header.x-response-id']);
        $this->assertSame(200, $span->getData()['http.response.status_code']);
    }

    /**
     * @return array{GuardedHttpClientRequest, GuardedHttpClientResponse}
     */
    private function guardedHttpExchange(): array
    {
        return [
            new GuardedHttpClientRequest(new PsrRequest('GET', 'https://example.com', ['X-Request-Id' => 'request-id'])),
            new GuardedHttpClientResponse(new PsrResponse(200, ['X-Response-Id' => 'response-id'])),
        ];
    }

    /**
     * @param array<string, mixed> $dataCollection
     */
    private function clientWithDataCollection(array $dataCollection): \Sentry\ClientInterface
    {
        return ClientBuilder::create([
            'data_collection' => $dataCollection,
            'default_integrations' => false,
            'integrations' => [new LaravelIntegration()],
        ])->getClient();
    }

    private function lastBreadcrumbFromScope(Scope $scope): Breadcrumb
    {
        $property = new ReflectionProperty(Scope::class, 'breadcrumbs');
        if (\PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }

        $breadcrumbs = $property->getValue($scope);
        $breadcrumb = end($breadcrumbs);
        $this->assertInstanceOf(Breadcrumb::class, $breadcrumb);

        return $breadcrumb;
    }

    /**
     * @return array{Request, Response}
     */
    private function httpExchange(): array
    {
        return [
            new Request(new PsrRequest('POST', 'https://example.com/path?query=value', [
                'X-Request-Id' => 'request-id',
                'Authorization' => 'Bearer secret',
                'Cookie' => 'session=secret',
            ], 'request body')),
            new Response(new PsrResponse(200, [
                'X-Response-Id' => ['one', 'two'],
                'X-Other' => 'other',
                'Set-Cookie' => 'session=secret',
            ], 'response body')),
        ];
    }

    /**
     * @param Span[] $spans
     */
    private function lastSpan(array $spans): Span
    {
        return $spans[count($spans) - 1];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function headerData(array $data): array
    {
        return array_filter($data, static function ($key): bool {
            return strpos($key, 'header.') !== false;
        }, \ARRAY_FILTER_USE_KEY);
    }
}

if (class_exists(Request::class)) {
    class GuardedHttpClientRequest extends Request
    {
        /** @var int */
        private $headersCallCount = 0;

        public function headers()
        {
            ++$this->headersCallCount;

            throw new \RuntimeException('Request headers must not be acquired on this path.');
        }

        public function getHeadersCallCount(): int
        {
            return $this->headersCallCount;
        }
    }
}

if (class_exists(Response::class)) {
    class GuardedHttpClientResponse extends Response
    {
        /** @var int */
        private $headersCallCount = 0;

        public function headers()
        {
            ++$this->headersCallCount;

            throw new \RuntimeException('Response headers must not be acquired on this path.');
        }

        public function getHeadersCallCount(): int
        {
            return $this->headersCallCount;
        }
    }
}
