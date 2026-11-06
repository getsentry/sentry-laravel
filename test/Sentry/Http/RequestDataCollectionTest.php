<?php

namespace Sentry\Laravel\Tests\Http;

use Sentry\Laravel\Tests\TestCase;

use function Sentry\captureMessage;

class RequestDataCollectionTest extends TestCase
{
    public function testUrlHeadersAndCookiesAreFiltered(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
        ]);

        $request = $this->captureRequestData('GET', '/data-collection?token=secret&page=5', [], [
            'laravel_session' => 'foo',
            'remember_web_59ba36addc2b2f9401580f014c7f58ea4e30989d' => 'bar',
            'theme' => 'dark',
        ], [
            'REMOTE_ADDR' => '1.2.3.4',
            'HTTP_AUTHORIZATION' => 'Bearer foo',
            'HTTP_X_REQUEST_ID' => 'bar',
            'HTTP_COOKIE' => 'theme=dark',
        ]);

        $this->assertSame('http://localhost/data-collection?token=[Filtered]&page=5', $request['url']);
        $this->assertSame('token=[Filtered]&page=5', $request['query_string']);
        $this->assertSame(['REMOTE_ADDR' => '1.2.3.4'], $request['env']);
        $this->assertSame([
            'laravel_session' => '[Filtered]',
            'remember_web_59ba36addc2b2f9401580f014c7f58ea4e30989d' => '[Filtered]',
            'theme' => 'dark',
        ], $request['cookies']);
        $this->assertSame(['[Filtered]'], $request['headers']['authorization']);
        $this->assertSame(['bar'], $request['headers']['x-request-id']);
        $this->assertArrayNotHasKey('cookie', $request['headers']);
        $this->assertArrayNotHasKey('data', $request);
    }

    public function testJsonBodyIsFiltered(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
        ]);

        $request = $this->captureRequestData('POST', '/data-collection', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{"username":"jane","password":"secret"}');

        $this->assertSame(['username' => 'jane', 'password' => '[Filtered]'], $request['data']);
    }

    public function testFormBodyIsFiltered(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
        ]);

        $request = $this->captureRequestData('POST', '/data-collection', [
            'username' => 'jane',
            'password' => 'secret',
        ]);

        $this->assertSame(['username' => 'jane', 'password' => '[Filtered]'], $request['data']);
    }

    public function testRawBodyIsReplaced(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
        ]);

        $request = $this->captureRequestData('POST', '/data-collection', [], [], [
            'CONTENT_TYPE' => 'text/plain',
            'CONTENT_LENGTH' => '11',
        ], 'Hello World');

        $this->assertSame('[Filtered]', $request['data']);
    }

    public function testRequestDataIsNotCollectedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [
                'user_info' => false,
                'cookies' => ['mode' => 'off'],
                'http_headers' => ['mode' => 'off'],
                'http_bodies' => ['outgoingRequest', 'incomingResponse', 'outgoingResponse'],
                'url_query_params' => ['mode' => 'off'],
            ],
        ]);

        $request = $this->captureRequestData('POST', '/data-collection?token=secret&page=5', [], [
            'theme' => 'dark',
        ], [
            'REMOTE_ADDR' => '1.2.3.4',
            'CONTENT_TYPE' => 'application/json',
        ], '{"username":"jane"}');

        $this->assertSame('http://localhost/data-collection', $request['url']);
        $this->assertArrayNotHasKey('query_string', $request);
        $this->assertArrayNotHasKey('env', $request);
        $this->assertArrayNotHasKey('cookies', $request);
        $this->assertArrayNotHasKey('headers', $request);
        $this->assertArrayNotHasKey('data', $request);
        $this->assertNull($this->getLastSentryEvent()->getUser());
    }

    public function testLegacyRequestDataIsNotChanged(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => false,
        ]);

        $request = $this->captureRequestData('POST', '/data-collection?token=secret', [], [
            'theme' => 'dark',
        ], [
            'REMOTE_ADDR' => '1.2.3.4',
            'CONTENT_TYPE' => 'text/plain',
            'CONTENT_LENGTH' => '11',
            'HTTP_AUTHORIZATION' => 'Bearer foo',
        ], 'Hello World');

        $this->assertSame('http://localhost/data-collection?token=secret', $request['url']);
        $this->assertSame('token=secret', $request['query_string']);
        $this->assertArrayNotHasKey('env', $request);
        $this->assertArrayNotHasKey('cookies', $request);
        $this->assertSame(['[Filtered]'], $request['headers']['authorization']);
        $this->assertSame('Hello World', $request['data']);
    }

    private function captureRequestData(string $method, string $uri, array $parameters = [], array $cookies = [], array $server = [], ?string $content = null): array
    {
        $this->app['router']->any('/data-collection', static function () {
            captureMessage('data collection');
        });

        $this->call($method, $uri, $parameters, $cookies, [], $server, $content)->assertOk();

        $event = $this->getLastSentryEvent();

        $this->assertNotNull($event);

        return $event->getRequest();
    }
}
