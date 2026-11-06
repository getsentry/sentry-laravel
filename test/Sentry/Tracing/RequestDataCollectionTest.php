<?php

namespace Sentry\Laravel\Tests\Tracing;

use Sentry\EventType;
use Sentry\Laravel\Tests\TestCase;

class RequestDataCollectionTest extends TestCase
{
    public function testRequestHeadersCookiesAndBodyAreFiltered(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 1.0,
            'sentry.data_collection' => [],
        ]);

        $data = $this->getTransactionDataForRequest('POST', [], [
            'theme' => 'dark',
            'laravel_session' => 'foo',
            'remember_web_59ba36addc2b2f9401580f014c7f58ea4e30989d' => 'bar',
        ], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer foo',
            'HTTP_X_REQUEST_ID' => 'bar',
            'HTTP_COOKIE' => 'theme=dark',
        ], '{"username":"jane","password":"secret"}');

        $this->assertSame('application/json', $data['http.request.header.content-type']);
        $this->assertSame('bar', $data['http.request.header.x-request-id']);
        $this->assertSame('[Filtered]', $data['http.request.header.authorization']);
        $this->assertSame([
            'theme=dark',
            'laravel_session=[Filtered]',
            'remember_web_59ba36addc2b2f9401580f014c7f58ea4e30989d=[Filtered]',
        ], $data['http.request.header.cookie']);
        $this->assertSame('{"username":"jane","password":"[Filtered]"}', $data['http.request.body.data']);
    }

    public function testFormRequestBodyIsFiltered(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 1.0,
            'sentry.data_collection' => [],
        ]);

        $data = $this->getTransactionDataForRequest('POST', [
            'username' => 'jane',
            'password' => 'secret',
        ]);

        $this->assertSame('{"username":"jane","password":"[Filtered]"}', $data['http.request.body.data']);
    }

    public function testRawRequestBodyIsReplaced(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 1.0,
            'sentry.data_collection' => [],
        ]);

        $data = $this->getTransactionDataForRequest('POST', [], [], [
            'CONTENT_TYPE' => 'text/plain',
            'CONTENT_LENGTH' => '11',
        ], 'Hello World');

        $this->assertSame('[Filtered]', $data['http.request.body.data']);
    }

    public function testRequestBodyOverTheSizeLimitIsNotCollected(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 1.0,
            'sentry.max_request_body_size' => 'small',
            'sentry.data_collection' => [],
        ]);

        $content = json_encode(['data' => str_repeat('a', 1000)]);

        $data = $this->getTransactionDataForRequest('POST', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => (string) strlen($content),
        ], $content);

        $this->assertSame('application/json', $data['http.request.header.content-type']);
        $this->assertArrayNotHasKey('http.request.body.data', $data);
    }

    public function testRequestDataIsNotCollectedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 1.0,
            'sentry.data_collection' => [
                'cookies' => ['mode' => 'off'],
                'http_headers' => [
                    'request' => ['mode' => 'off'],
                ],
                'http_bodies' => ['outgoingRequest', 'incomingResponse', 'outgoingResponse'],
            ],
        ]);

        $data = $this->getTransactionDataForRequest('POST', [], [
            'theme' => 'dark',
        ], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_REQUEST_ID' => 'bar',
        ], '{"username":"jane"}');

        $this->assertSame([], $this->getRequestDataKeys($data));
    }

    public function testRequestDataIsNotCollectedWithoutDataCollection(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 1.0,
            'sentry.send_default_pii' => true,
        ]);

        $data = $this->getTransactionDataForRequest('POST', [], [
            'theme' => 'dark',
        ], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_REQUEST_ID' => 'bar',
        ], '{"username":"jane"}');

        $this->assertSame([], $this->getRequestDataKeys($data));
    }

    private function getTransactionDataForRequest(string $method, array $parameters = [], array $cookies = [], array $server = [], ?string $content = null): array
    {
        $this->app['router']->any('/request', static function () {
            return 'ok';
        });

        $this->call($method, '/request', $parameters, $cookies, [], $server, $content)->assertOk();

        $transaction = $this->getLastSentryEvent();

        $this->assertNotNull($transaction);
        $this->assertSame(EventType::transaction(), $transaction->getType());

        return $transaction->getContexts()['trace']['data'];
    }

    /**
     * @return string[]
     */
    private function getRequestDataKeys(array $data): array
    {
        return array_values(array_filter(array_keys($data), static function (string $key): bool {
            return strpos($key, 'http.request.header.') === 0 || $key === 'http.request.body.data';
        }));
    }
}
