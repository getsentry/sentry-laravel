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
use Symfony\Component\HttpFoundation\StreamedResponse;

class HttpBodyCollectionTest extends TestCase
{
    public function testParsedRequestBodyIsCollectedOnServerTransaction(): void
    {
        $request = Request::create('/bodies', 'POST', [
            'name' => 'Alice',
            'password' => 'secret',
        ]);
        list($middleware, $transaction) = $this->handleRequest($request);

        $middleware->terminate($request, new Response());

        $this->assertSame(
            ['name' => 'Alice', 'password' => '[Filtered]'],
            $transaction->getData()['http.request.body.data']
        );
        $this->assertSame('secret', $request->request->get('password'));
    }

    public function testRawRequestBodyIsCollectedWithoutMakingItUnreadable(): void
    {
        $body = '{"name":"Alice","password":"secret"}';
        $request = Request::create(
            '/bodies',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $body
        );
        list($middleware, $transaction) = $this->handleRequest($request);

        $middleware->terminate($request, new Response());

        $this->assertSame(
            ['name' => 'Alice', 'password' => '[Filtered]'],
            $transaction->getData()['http.request.body.data']
        );
        $this->assertSame($body, $request->getContent());
    }

    public function testResponseBodyIsCollectedOnServerTransaction(): void
    {
        $request = Request::create('/bodies');
        list($middleware, $transaction) = $this->handleRequest($request);

        $middleware->terminate($request, new Response(
            '{"ok":true,"token":"secret"}',
            200,
            ['Content-Type' => 'application/json']
        ));

        $this->assertSame(
            ['ok' => true, 'token' => '[Filtered]'],
            $transaction->getData()['http.response.body.data']
        );
    }

    public function testStreamedResponseBodyIsNotCollectedOrInvoked(): void
    {
        $invoked = false;
        $request = Request::create('/bodies');
        list($middleware, $transaction) = $this->handleRequest($request);
        $response = new StreamedResponse(static function () use (&$invoked): void {
            $invoked = true;
        });

        $middleware->terminate($request, $response);

        $this->assertFalse($invoked);
        $this->assertArrayNotHasKey('http.response.body.data', $transaction->getData());
    }

    public function testDisabledBodyCollectionDoesNotReadBodies(): void
    {
        $this->resetApplicationWithConfig($this->tracingConfig([]));
        $request = $this->getMockBuilder(Request::class)->onlyMethods(['getContent'])->getMock();
        $request->expects($this->never())->method('getContent');
        $response = $this->getMockBuilder(Response::class)->onlyMethods(['getContent'])->getMock();
        $response->expects($this->never())->method('getContent');
        list($middleware, $transaction) = $this->handleRequest($request, false);

        $middleware->terminate($request, $response);

        $this->assertArrayNotHasKey('http.request.body.data', $transaction->getData());
        $this->assertArrayNotHasKey('http.response.body.data', $transaction->getData());
    }

    public function testLegacyModeDoesNotReadBodies(): void
    {
        $this->resetApplicationWithConfig($this->tracingConfig(null));
        $request = $this->getMockBuilder(Request::class)->onlyMethods(['getContent'])->getMock();
        $request->expects($this->never())->method('getContent');
        $response = $this->getMockBuilder(Response::class)->onlyMethods(['getContent'])->getMock();
        $response->expects($this->never())->method('getContent');
        list($middleware, $transaction) = $this->handleRequest($request, false);

        $middleware->terminate($request, $response);

        $this->assertArrayNotHasKey('http.request.body.data', $transaction->getData());
        $this->assertArrayNotHasKey('http.response.body.data', $transaction->getData());
    }

    public function testOversizedRequestBodyIsNotRead(): void
    {
        $config = $this->tracingConfig(['incomingRequest']);
        $config['sentry.max_request_body_size'] = 'small';
        $this->resetApplicationWithConfig($config);
        $request = $this->getMockBuilder(Request::class)->onlyMethods(['getContent'])->getMock();
        $request->headers->set('Content-Length', '1001');
        $request->expects($this->never())->method('getContent');
        list($middleware, $transaction) = $this->handleRequest($request, false);

        $middleware->terminate($request, new Response());

        $this->assertArrayNotHasKey('http.request.body.data', $transaction->getData());
    }

    public function testExplicitBodyDataIsPreservedWithoutReadingBodies(): void
    {
        $this->resetApplicationWithConfig($this->tracingConfig([
            'incomingRequest',
            'outgoingResponse',
        ]));
        $originalHub = SentrySdk::getCurrentHub();
        $hub = new BodyDataEnrichingHub($this->getSentryClientFromContainer(), [
            'http.request.body.data' => null,
            'http.response.body.data' => [],
        ]);
        $request = $this->getMockBuilder(Request::class)->onlyMethods(['getContent'])->getMock();
        $request->expects($this->never())->method('getContent');
        $response = $this->getMockBuilder(Response::class)->onlyMethods(['getContent'])->getMock();
        $response->expects($this->never())->method('getContent');

        try {
            SentrySdk::setCurrentHub($hub);
            list($middleware, $transaction) = $this->handleRequest($request, false);
            $middleware->terminate($request, $response);
        } finally {
            SentrySdk::setCurrentHub($originalHub);
        }

        $this->assertNull($transaction->getData()['http.request.body.data']);
        $this->assertSame([], $transaction->getData()['http.response.body.data']);
    }

    /**
     * @param string[]|null $httpBodies
     *
     * @return array<string, mixed>
     */
    private function tracingConfig(?array $httpBodies): array
    {
        return [
            'sentry.traces_sample_rate' => 1.0,
            'sentry.tracing.continue_after_response' => false,
            'sentry.tracing.missing_routes' => true,
            'sentry.data_collection' => $httpBodies === null ? null : [
                'cookies' => ['mode' => 'off'],
                'http_bodies' => $httpBodies,
                'http_headers' => ['mode' => 'off'],
            ],
        ];
    }

    /**
     * @return array{Middleware, Transaction}
     */
    private function handleRequest(Request $request, bool $resetApplication = true): array
    {
        if ($resetApplication) {
            $this->resetApplicationWithConfig($this->tracingConfig([
                'incomingRequest',
                'outgoingResponse',
            ]));
        }

        $middleware = $this->app->make(Middleware::class);
        $middleware->handle($request, static function () {
            return new Response();
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

class BodyDataEnrichingHub extends Hub
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
