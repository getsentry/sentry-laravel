<?php

namespace Sentry\Laravel\Tests\Http;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
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

    public function testEmptyParsedBodyIsDroppedForRequestsThatAreNotForms(): void
    {
        $request = (new ServerRequest('POST', 'http://localhost/', ['Content-Type' => 'text/plain']))->withParsedBody([]);

        $this->assertNull($this->fetchRequest($request)->getParsedBody());
    }

    public function testEmptyParsedBodyIsKeptForForms(): void
    {
        $request = (new ServerRequest('POST', 'http://localhost/', ['Content-Type' => 'Multipart/Form-Data; boundary=foo']))->withParsedBody([]);

        $this->assertSame([], $this->fetchRequest($request)->getParsedBody());
    }

    public function testParsedBodyIsKept(): void
    {
        $request = (new ServerRequest('POST', 'http://localhost/', ['Content-Type' => 'application/json']))->withParsedBody(['foo' => 'bar']);

        $this->assertSame(['foo' => 'bar'], $this->fetchRequest($request)->getParsedBody());
    }

    public function testParsedBodyIsDroppedForRequestsThatAreNotFormsWithDataCollection(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
        ]);

        // Laravel 8 and older use the query parameters as the parsed body of GET requests
        $request = (new ServerRequest('GET', 'http://localhost/?token=secret'))->withParsedBody(['token' => 'secret']);

        $this->assertNull($this->fetchRequest($request)->getParsedBody());
    }

    public function testParsedBodyIsKeptForFormsWithDataCollection(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
        ]);

        $request = (new ServerRequest('POST', 'http://localhost/', ['Content-Type' => 'application/x-www-form-urlencoded']))->withParsedBody(['foo' => 'bar']);

        $this->assertSame(['foo' => 'bar'], $this->fetchRequest($request)->getParsedBody());
    }

    public function testRawQueryStringIsUsedWithDataCollection(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
        ]);

        $request = new ServerRequest('GET', 'http://localhost/?page=5&q=a%20b', [], null, '1.1', ['QUERY_STRING' => 'q=a+b&page=5']);

        $this->assertSame('q=a+b&page=5', $this->fetchRequest($request)->getUri()->getQuery());
    }

    public function testQueryStringIsNotChangedWithoutDataCollection(): void
    {
        $request = new ServerRequest('GET', 'http://localhost/?page=5&q=a%20b', [], null, '1.1', ['QUERY_STRING' => 'q=a+b&page=5']);

        $this->assertSame('page=5&q=a%20b', $this->fetchRequest($request)->getUri()->getQuery());
    }

    private function fetchRequest(ServerRequest $request): ServerRequestInterface
    {
        $this->app->instance(LaravelRequestFetcher::CONTAINER_PSR7_INSTANCE_KEY, $request);

        $fetchedRequest = (new LaravelRequestFetcher)->fetchRequest();

        $this->assertNotNull($fetchedRequest);

        return $fetchedRequest;
    }
}
