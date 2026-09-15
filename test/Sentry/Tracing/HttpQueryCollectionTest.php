<?php

namespace Sentry\Laravel\Tests\Tracing;

use Illuminate\Http\Request;
use ReflectionProperty;
use Sentry\ClientInterface;
use Sentry\Laravel\Tests\TestCase;
use Sentry\Laravel\Tracing\Middleware;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Symfony\Component\HttpFoundation\Response;

class HttpQueryCollectionTest extends TestCase
{
    public function testConfiguredPolicyCollectsRawFilteredQueryWithoutChangingRequest(): void
    {
        $this->resetApplicationWithConfig($this->tracingConfig([]));
        $request = Request::create('/search?normalized=value', 'GET');
        $request->server->set('QUERY_STRING', 'tag=a&tag=b&%74oken=secret&q=a+b');
        $originalUri = $request->getRequestUri();

        list($middleware, $transaction) = $this->handleRequest($request);

        $this->assertSame('tag=a&tag=b&%74oken=[Filtered]&q=a+b', $transaction->getData()['http.query']);
        $this->assertSame('/search', $transaction->getName());
        $this->assertSame('/search', $transaction->getData()['url']);
        $this->assertSame($originalUri, $request->getRequestUri());
        $middleware->terminate($request, new Response('ok'));
    }

    public function testOffEmptyAndLegacyPoliciesDoNotAddServerQueryAttribute(): void
    {
        $this->resetApplicationWithConfig($this->tracingConfig(['url_query_params' => ['mode' => 'off']]));
        $request = Request::create('/search?token=secret', 'GET');
        list($middleware, $transaction) = $this->handleRequest($request);
        $this->assertArrayNotHasKey('http.query', $transaction->getData());
        $middleware->terminate($request, new Response('ok'));

        $this->resetApplicationWithConfig($this->tracingConfig([]));
        $request = Request::create('/search', 'GET');
        list($middleware, $transaction) = $this->handleRequest($request);
        $this->assertArrayNotHasKey('http.query', $transaction->getData());
        $middleware->terminate($request, new Response('ok'));

        $this->resetApplicationWithConfig($this->tracingConfig(null));
        $request = Request::create('/search?token=secret', 'GET');
        list($middleware, $transaction) = $this->handleRequest($request);
        $this->assertArrayNotHasKey('http.query', $transaction->getData());
        $middleware->terminate($request, new Response('ok'));
    }

    public function testAllowListAndMandatorySensitiveFilteringAreApplied(): void
    {
        $this->resetApplicationWithConfig($this->tracingConfig([
            'url_query_params' => ['mode' => 'allowList', 'terms' => ['visible', 'token']],
        ]));
        $request = Request::create('/search?visible=yes&other=no&token=secret', 'GET');

        list($middleware, $transaction) = $this->handleRequest($request);

        $this->assertSame('visible=yes&other=[Filtered]&token=[Filtered]', $transaction->getData()['http.query']);
        $middleware->terminate($request, new Response('ok'));
    }

    public function testExplicitTransactionQueryAttributeWins(): void
    {
        $this->resetApplicationWithConfig($this->tracingConfig([]));
        $originalHub = SentrySdk::getCurrentHub();
        $hub = new QueryDataEnrichingHub($this->getSentryClientFromContainer(), ['http.query' => null]);
        $request = Request::create('/search?query=collected', 'GET');

        try {
            SentrySdk::setCurrentHub($hub);
            list($middleware, $transaction) = $this->handleRequest($request);
            $this->assertArrayHasKey('http.query', $transaction->getData());
            $this->assertNull($transaction->getData()['http.query']);
            $middleware->terminate($request, new Response('ok'));
        } finally {
            SentrySdk::setCurrentHub($originalHub);
        }
    }

    public function testRuntimeQueryPolicyChangeAffectsNextRequest(): void
    {
        $this->resetApplicationWithConfig($this->tracingConfig([]));
        $request = Request::create('/search?first=one', 'GET');
        list($middleware, $transaction) = $this->handleRequest($request);
        $this->assertSame('first=one', $transaction->getData()['http.query']);
        $middleware->terminate($request, new Response('ok'));

        SentrySdk::getCurrentHub()->setSpan(null);
        $this->getSentryClientFromContainer()->getOptions()->getDataCollection()->setUrlQueryParams(['mode' => 'off']);
        $request = Request::create('/search?second=two', 'GET');
        list($middleware, $transaction) = $this->handleRequest($request);
        $this->assertArrayNotHasKey('http.query', $transaction->getData());
        $middleware->terminate($request, new Response('ok'));
    }

    /** @param array<string, mixed>|null $dataCollection */
    private function tracingConfig(?array $dataCollection): array
    {
        return [
            'sentry.traces_sample_rate' => 1.0,
            'sentry.tracing.continue_after_response' => false,
            'sentry.tracing.missing_routes' => true,
            'sentry.data_collection' => $dataCollection,
        ];
    }

    /** @return array{Middleware, Transaction} */
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
}

class QueryDataEnrichingHub extends Hub
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
