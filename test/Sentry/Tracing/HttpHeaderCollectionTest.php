<?php

namespace Sentry\Laravel\Tests\Tracing;

use Illuminate\Http\Request;
use ReflectionProperty;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\EventType;
use Sentry\Laravel\Tests\TestCase;
use Sentry\Laravel\Tracing\Middleware;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class HttpHeaderCollectionTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->get('/header-lifecycle/{value}', static function (string $value) {
            return new Response('ok', 200, ['X-Response-Id' => 'response-' . $value]);
        });
    }

    public function testRequestAndResponseHeadersAreCollectedOnServerTransaction(): void
    {
        $this->resetApplicationWithConfig($this->tracingConfig([]));

        $request = Request::create('/headers', 'GET');
        $request->headers->set('X-Request-Id', ['first', 'second']);
        $request->headers->set('Authorization', 'Bearer secret');
        $request->headers->set('Cookie', 'session=secret');

        list($middleware, $transaction) = $this->handleRequest($request);

        $response = new Response('ok', 201, [
            'X-Response-Id' => ['one', 'two'],
            'X-Api-Token' => 'secret',
        ]);
        $response->headers->setCookie(new ThrowingStringCookie('session', 'secret'));
        $middleware->terminate($request, $response);

        $data = $transaction->getData();
        $this->assertSame(['first', 'second'], $data['http.request.header.x-request-id']);
        $this->assertSame(['[Filtered]'], $data['http.request.header.authorization']);
        $this->assertSame(['one', 'two'], $data['http.response.header.x-response-id']);
        $this->assertSame(['[Filtered]'], $data['http.response.header.x-api-token']);
        $this->assertSame(201, $data['http.response.status_code']);
        $this->assertArrayNotHasKey('http.request.header.cookie', $data);
        $this->assertArrayNotHasKey('http.request.header.cookie.session', $data);
        $this->assertArrayNotHasKey('http.response.header.set-cookie', $data);
        $this->assertArrayNotHasKey('http.response.header.set_cookie.session', $data);
    }

    public function testLegacyAndOffPoliciesDoNotAcquireHeadersOrAddAttributes(): void
    {
        $this->resetApplicationWithConfig($this->tracingConfig(null));
        list($request, $requestHeaders) = $this->guardedRequest('/legacy');
        list($response, $responseHeaders) = $this->guardedResponse();
        list($middleware, $transaction) = $this->handleRequest($request);
        $middleware->terminate($request, $response);
        $this->assertSame([], $this->headerData($transaction));
        $this->assertSame(0, $requestHeaders->getIteratorCallCount());
        $this->assertSame(0, $responseHeaders->getIteratorCallCount());

        $this->resetApplicationWithConfig($this->tracingConfig([
            'http_headers' => ['mode' => 'off'],
        ]));
        list($request, $requestHeaders) = $this->guardedRequest('/off');
        list($response, $responseHeaders) = $this->guardedResponse();
        list($middleware, $transaction) = $this->handleRequest($request);
        $middleware->terminate($request, $response);
        $this->assertSame([], $this->headerData($transaction));
        $this->assertSame(0, $requestHeaders->getIteratorCallCount());
        $this->assertSame(0, $responseHeaders->getIteratorCallCount());
    }

    public function testDirectionsAndFilteringAreIndependent(): void
    {
        $this->resetApplicationWithConfig($this->tracingConfig([
            'http_headers' => [
                'request' => ['mode' => 'allowList', 'terms' => ['x-request-id', 'cookie']],
                'response' => ['mode' => 'off'],
            ],
        ]));

        $request = Request::create('/headers', 'GET', [], [], [], [
            'HTTP_X_REQUEST_ID' => 'request-id',
            'HTTP_X_OTHER' => 'other',
            'HTTP_COOKIE' => 'session=secret',
        ]);
        list($middleware, $transaction) = $this->handleRequest($request);
        $middleware->terminate($request, new Response('ok', 200, ['X-Response-Id' => 'response-id']));

        $data = $transaction->getData();
        $this->assertSame(['request-id'], $data['http.request.header.x-request-id']);
        $this->assertSame(['[Filtered]'], $data['http.request.header.x-other']);
        $this->assertArrayNotHasKey('http.request.header.cookie', $data);
        $this->assertArrayNotHasKey('http.response.header.x-response-id', $data);
    }

    public function testUnsampledTransactionDoesNotAcquireHeaders(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 0.0,
            'sentry.tracing.missing_routes' => true,
            'sentry.data_collection' => [],
        ]);

        list($request, $headers) = $this->guardedRequest('/unsampled');
        $middleware = $this->app->make(Middleware::class);
        $middleware->handle($request, static function () {
            return new Response('ok');
        });

        $property = new ReflectionProperty(Middleware::class, 'transaction');
        if (\PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }

        $this->assertNull($property->getValue($middleware));
        $this->assertSame(0, $headers->getIteratorCallCount());
    }

    public function testMissingClientDoesNotAcquireHeaders(): void
    {
        $this->resetApplicationWithConfig($this->tracingConfig([]));
        list($request, $headers) = $this->guardedRequest('/no-client');
        $originalHub = SentrySdk::getCurrentHub();

        try {
            SentrySdk::setCurrentHub(new Hub());

            $this->app->make(Middleware::class)->handle($request, static function () {
                return new Response('ok');
            });
        } finally {
            SentrySdk::setCurrentHub($originalHub);
        }

        $this->assertSame(0, $headers->getIteratorCallCount());
    }

    public function testResponseUsesCurrentPolicyAndContinueAfterResponseStillCollects(): void
    {
        $config = $this->tracingConfig([]);
        $config['sentry.tracing.continue_after_response'] = true;
        $this->resetApplicationWithConfig($config);

        $request = Request::create('/headers', 'GET', [], [], [], ['HTTP_X_REQUEST_ID' => 'request-id']);
        list($middleware, $transaction) = $this->handleRequest($request);

        $this->getSentryClientFromContainer()->getOptions()->getDataCollection()->setHttpHeaders([
            'request' => ['mode' => 'denyList'],
            'response' => ['mode' => 'off'],
        ]);
        $middleware->terminate($request, new Response('ok', 200, ['X-Response-Id' => 'response-id']));

        $this->assertSame(['request-id'], $transaction->getData()['http.request.header.x-request-id']);
        $this->assertArrayNotHasKey('http.response.header.x-response-id', $transaction->getData());

        $middleware->finishTransaction();
    }

    public function testExplicitRequestHeaderDataWins(): void
    {
        $this->resetApplicationWithConfig($this->tracingConfig([]));
        $originalHub = SentrySdk::getCurrentHub();
        $hub = new RequestDataEnrichingHub($this->getSentryClientFromContainer(), [
            'http.request.header.x-explicit-null' => null,
            'http.request.header.x-explicit-empty' => [],
        ]);
        $request = Request::create('/headers', 'GET', [], [], [], [
            'HTTP_X_EXPLICIT_NULL' => 'collected-null',
            'HTTP_X_EXPLICIT_EMPTY' => 'collected-empty',
            'HTTP_X_COLLECTED' => 'collected',
        ]);

        try {
            SentrySdk::setCurrentHub($hub);
            list($middleware, $transaction) = $this->handleRequest($request);
            $middleware->terminate($request, new Response('ok'));
        } finally {
            SentrySdk::setCurrentHub($originalHub);
        }

        $this->assertNull($transaction->getData()['http.request.header.x-explicit-null']);
        $this->assertSame([], $transaction->getData()['http.request.header.x-explicit-empty']);
        $this->assertSame(['collected'], $transaction->getData()['http.request.header.x-collected']);
    }

    public function testResponseHeadersAddedAfterHandlingAreCollectedAndExplicitDataWins(): void
    {
        $this->resetApplicationWithConfig($this->tracingConfig([]));

        $request = Request::create('/headers', 'GET');
        list($middleware, $transaction) = $this->handleRequest($request);
        $transaction->setData(['http.response.header.x-response-id' => null]);

        $response = new Response('ok');
        $response->headers->set('X-Response-Id', 'response-id');
        $response->headers->set('X-Late', 'late');
        $middleware->terminate($request, $response);

        $this->assertNull($transaction->getData()['http.response.header.x-response-id']);
        $this->assertSame(['late'], $transaction->getData()['http.response.header.x-late']);
    }

    public function testKernelTerminationCollectsOnceAndDoesNotLeakHeadersOrPoliciesAcrossRequests(): void
    {
        $config = $this->tracingConfig([]);
        $config['sentry.tracing.continue_after_response'] = true;
        $this->resetApplicationWithConfig($config);
        $middleware = $this->app->make(Middleware::class);

        $this->get('/header-lifecycle/first', ['X-Request-Id' => 'request-first'])->assertOk();

        $this->assertSentryTransactionCount(1);
        $firstData = $this->lastCapturedTransactionData();
        $this->assertSame(['request-first'], $firstData['http.request.header.x-request-id']);
        $this->assertSame(['response-first'], $firstData['http.response.header.x-response-id']);

        $middleware->finishTransaction();
        $middleware->finishTransaction();
        $this->assertSentryTransactionCount(1);

        SentrySdk::getCurrentHub()->setSpan(null);
        $this->getSentryClientFromContainer()->getOptions()->getDataCollection()->setHttpHeaders(['mode' => 'off']);
        $this->get('/header-lifecycle/second', ['X-Request-Id' => 'request-second'])->assertOk();

        $this->assertSame($middleware, $this->app->make(Middleware::class));
        $this->assertSentryTransactionCount(2);
        $secondData = $this->lastCapturedTransactionData();
        $this->assertSame([], $this->headerDataArray($secondData));

        SentrySdk::getCurrentHub()->setSpan(null);
        $this->getSentryClientFromContainer()->getOptions()->getDataCollection()->setHttpHeaders([
            'mode' => 'denyList',
            'terms' => [],
        ]);
        $this->get('/header-lifecycle/third', ['X-Request-Id' => 'request-third'])->assertOk();

        $this->assertSame($middleware, $this->app->make(Middleware::class));
        $this->assertSentryTransactionCount(3);
        $thirdData = $this->lastCapturedTransactionData();
        $this->assertSame(['request-third'], $thirdData['http.request.header.x-request-id']);
        $this->assertSame(['response-third'], $thirdData['http.response.header.x-response-id']);
        $this->assertNotContains('request-first', $thirdData['http.request.header.x-request-id']);
        $this->assertNotContains('response-first', $thirdData['http.response.header.x-response-id']);
    }

    /**
     * @param array<string, mixed>|null $dataCollection
     *
     * @return array<string, mixed>
     */
    private function tracingConfig(?array $dataCollection): array
    {
        if ($dataCollection !== null) {
            $dataCollection += ['cookies' => ['mode' => 'off']];
        }

        return [
            'sentry.traces_sample_rate' => 1.0,
            'sentry.tracing.continue_after_response' => false,
            'sentry.tracing.missing_routes' => true,
            'sentry.data_collection' => $dataCollection,
        ];
    }

    /**
     * @return array{Middleware, Transaction}
     */
    private function handleRequest(Request $request): array
    {
        $middleware = $this->app->make(Middleware::class);
        $middleware->handle($request, static function () {
            return new Response('ok');
        });

        $property = new ReflectionProperty(Middleware::class, 'transaction');
        if (\PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }

        $transaction = $property->getValue($middleware);
        $this->assertInstanceOf(Transaction::class, $transaction);

        return [$middleware, $transaction];
    }

    /**
     * @return array{Request, GuardedHeaderBag}
     */
    private function guardedRequest(string $path): array
    {
        $request = Request::create($path, 'GET');
        $headers = new GuardedHeaderBag(['X-Request-Id' => 'request-id']);
        $request->headers = $headers;

        return [$request, $headers];
    }

    /**
     * @return array{Response, GuardedResponseHeaderBag}
     */
    private function guardedResponse(): array
    {
        $response = new Response('ok');
        $headers = new GuardedResponseHeaderBag(['X-Response-Id' => 'response-id']);
        $response->headers = $headers;

        return [$response, $headers];
    }

    /**
     * @return array<string, mixed>
     */
    private function lastCapturedTransactionData(): array
    {
        $transactions = array_values(array_filter($this->getCapturedSentryEvents(), static function (array $captured): bool {
            return $captured[0]->getType() === EventType::transaction();
        }));
        /** @var Event $event */
        $event = $transactions[count($transactions) - 1][0];

        return $event->getContexts()['trace']['data'];
    }

    /**
     * @return array<string, mixed>
     */
    private function headerData(Transaction $transaction): array
    {
        return $this->headerDataArray($transaction->getData());
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function headerDataArray(array $data): array
    {
        return array_filter($data, static function ($key): bool {
            return strpos($key, 'header.') !== false;
        }, \ARRAY_FILTER_USE_KEY);
    }
}

class RequestDataEnrichingHub extends Hub
{
    /** @var array<string, mixed> */
    private $data;

    /** @param array<string, mixed> $data */
    public function __construct(ClientInterface $client, array $data)
    {
        parent::__construct($client);

        $this->data = $data;
    }

    public function startTransaction(TransactionContext $context, array $customSamplingContext = []): Transaction
    {
        $transaction = parent::startTransaction($context, $customSamplingContext);
        $transaction->setData($this->data);

        return $transaction;
    }
}

class GuardedHeaderBag extends HeaderBag
{
    /** @var int */
    private $getIteratorCallCount = 0;

    public function getIterator(): \ArrayIterator
    {
        ++$this->getIteratorCallCount;

        throw new \RuntimeException('Request headers must not be enumerated on this path.');
    }

    public function getIteratorCallCount(): int
    {
        return $this->getIteratorCallCount;
    }
}

class GuardedResponseHeaderBag extends ResponseHeaderBag
{
    /** @var int */
    private $getIteratorCallCount = 0;

    public function getIterator(): \ArrayIterator
    {
        ++$this->getIteratorCallCount;

        throw new \RuntimeException('Response headers must not be enumerated on this path.');
    }

    public function getIteratorCallCount(): int
    {
        return $this->getIteratorCallCount;
    }
}

class ThrowingStringCookie extends Cookie
{
    public function __toString(): string
    {
        throw new \RuntimeException('The cookie must not be serialized while collecting regular headers.');
    }
}
