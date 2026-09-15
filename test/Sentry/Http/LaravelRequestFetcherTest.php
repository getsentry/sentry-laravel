<?php

namespace Sentry\Laravel\Tests\Http;

use Nyholm\Psr7\ServerRequest;
use Sentry\Laravel\Http\LaravelRequestFetcher;
use Sentry\Laravel\Tests\TestCase;

class LaravelRequestFetcherTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->get('/', function () {
            return 'Hello!';
        });
    }

    public function testPsr7InstanceCanBeResolved(): void
    {
        // The request is only set on the container if we made a request (it is resolved by the SetRequestMiddleware)
        $this->get('/');

        $instance = $this->app->make(LaravelRequestFetcher::CONTAINER_PSR7_INSTANCE_KEY);

        $this->assertInstanceOf(ServerRequest::class, $instance);
    }

    public function testConfiguredCollectionRestoresRawServerQueryWithoutChangingCachedRequest(): void
    {
        $this->resetApplicationWithConfig(['sentry.data_collection' => []]);
        $cached = new ServerRequest(
            'GET',
            'https://bridge.example/path?normalized=value',
            ['Host' => 'original.example'],
            null,
            '1.1',
            ['QUERY_STRING' => 'tag=a&tag=b&%74oken=secret']
        );
        $this->app->instance('request', \Illuminate\Http\Request::create('/'));
        $this->app->instance(LaravelRequestFetcher::CONTAINER_PSR7_INSTANCE_KEY, $cached);

        $fetched = (new LaravelRequestFetcher())->fetchRequest();

        $this->assertSame('tag=a&tag=b&%74oken=secret', $fetched->getUri()->getQuery());
        $this->assertSame('original.example', $fetched->getHeaderLine('Host'));
        $this->assertSame('normalized=value', $cached->getUri()->getQuery());
    }

    public function testLegacyAndInvalidServerQueriesRetainBridgeUri(): void
    {
        $this->resetApplicationWithConfig(['sentry.data_collection' => []]);
        $cached = new ServerRequest('GET', 'https://example.com/path?normalized=value', [], null, '1.1', [
            'QUERY_STRING' => ['invalid'],
        ]);
        $this->app->instance('request', \Illuminate\Http\Request::create('/'));
        $this->app->instance(LaravelRequestFetcher::CONTAINER_PSR7_INSTANCE_KEY, $cached);
        $this->assertSame('normalized=value', (new LaravelRequestFetcher())->fetchRequest()->getUri()->getQuery());

        $cached = new ServerRequest('GET', 'https://example.com/path?normalized=value');
        $this->app->instance(LaravelRequestFetcher::CONTAINER_PSR7_INSTANCE_KEY, $cached);
        $this->assertSame('normalized=value', (new LaravelRequestFetcher())->fetchRequest()->getUri()->getQuery());

        $this->resetApplicationWithConfig(['sentry.data_collection' => null]);
        $cached = new ServerRequest('GET', 'https://example.com/path?normalized=value', [], null, '1.1', [
            'QUERY_STRING' => 'raw=value',
        ]);
        $this->app->instance('request', \Illuminate\Http\Request::create('/'));
        $this->app->instance(LaravelRequestFetcher::CONTAINER_PSR7_INSTANCE_KEY, $cached);
        $this->assertSame('normalized=value', (new LaravelRequestFetcher())->fetchRequest()->getUri()->getQuery());
    }
}
