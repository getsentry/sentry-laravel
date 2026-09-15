<?php

namespace Sentry\Laravel\Tests\Http;

use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\ServerRequest;
use Sentry\Event;
use Sentry\Laravel\Http\LaravelRequestFetcher;
use Sentry\Laravel\Tests\TestCase;

class RequestBodyCollectionTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->post('/capture-body', static function () {
            \Sentry\captureMessage('capture request body');

            return 'ok';
        });

        $router->post('/capture-explicit-body', static function () {
            $event = Event::createEvent();
            $event->setRequest(['data' => null]);
            \Sentry\captureEvent($event);

            return 'ok';
        });
    }

    public function testConfiguredRequestBodyIsCollectedOnEvents(): void
    {
        $this->configure(['incomingRequest']);

        $this->postJson('/capture-body', [
            'name' => 'Alice',
            'password' => 'secret',
        ])->assertOk();

        $this->assertSame(
            ['name' => 'Alice', 'password' => '[Filtered]'],
            $this->getLastSentryEvent()->getRequest()['data']
        );
    }

    public function testRequestBodyStreamPositionIsPreserved(): void
    {
        $this->configure(['incomingRequest']);
        $request = new ServerRequest(
            'POST',
            'https://example.com',
            ['Content-Type' => 'application/json'],
            '{"name":"Alice","token":"secret"}'
        );
        $request->getBody()->seek(7);
        $this->app->instance(LaravelRequestFetcher::CONTAINER_PSR7_INSTANCE_KEY, $request);

        \Sentry\captureMessage('capture seekable request body');

        $this->assertSame(
            ['name' => 'Alice', 'token' => '[Filtered]'],
            $this->getLastSentryEvent()->getRequest()['data']
        );
        $this->assertSame(7, $request->getBody()->tell());
    }

    public function testNonSeekableRequestBodyIsNotCollected(): void
    {
        $this->configure(['incomingRequest']);
        $request = new ServerRequest(
            'POST',
            'https://example.com',
            ['Content-Type' => 'application/json'],
            '{"name":"Alice"}'
        );
        $request = $request->withBody(new NoSeekStream($request->getBody()));
        $this->app->instance(LaravelRequestFetcher::CONTAINER_PSR7_INSTANCE_KEY, $request);

        \Sentry\captureMessage('capture non-seekable request body');

        $this->assertArrayNotHasKey('data', $this->getLastSentryEvent()->getRequest());
    }

    public function testIncomingRequestBodyCollectionCanBeTurnedOff(): void
    {
        $this->configure([]);

        $this->postJson('/capture-body', ['name' => 'Alice'])->assertOk();

        $this->assertArrayNotHasKey('data', $this->getLastSentryEvent()->getRequest());
    }

    public function testExplicitRequestBodyIsPreserved(): void
    {
        $this->configure(['incomingRequest']);

        $this->postJson('/capture-explicit-body', ['name' => 'Alice'])->assertOk();

        $this->assertNull($this->getLastSentryEvent()->getRequest()['data']);
    }

    /** @param string[] $httpBodies */
    private function configure(array $httpBodies): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => ['http_bodies' => $httpBodies],
        ]);
    }
}
