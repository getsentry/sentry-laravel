<?php

namespace Sentry\Laravel\Tests\Tracing;

use Closure;
use Illuminate\Http\JsonResponse;
use Sentry\EventType;
use Sentry\Laravel\Tests\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResponseDataCollectionTest extends TestCase
{
    public function testResponseHeadersCookiesAndBodyAreFiltered(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 1.0,
            'sentry.data_collection' => [],
        ]);

        $data = $this->getTransactionDataForResponse(static function () {
            $response = new JsonResponse(['username' => 'jane', 'password' => 'secret'], 200, [
                'X-Request-Id' => 'bar',
                'X-Api-Key' => 'secret',
                'X-Served-By' => ['web-1', 'web-2'],
            ]);

            $response->headers->setCookie(Cookie::create('theme', 'dark'));
            $response->headers->setCookie(Cookie::create('laravel_session', 'foo'));
            $response->headers->setCookie(Cookie::create('remember_web_59ba36addc2b2f9401580f014c7f58ea4e30989d', 'bar'));

            return $response;
        });

        $this->assertSame('application/json', $data['http.response.header.content-type']);
        $this->assertSame('bar', $data['http.response.header.x-request-id']);
        $this->assertSame('[Filtered]', $data['http.response.header.x-api-key']);
        $this->assertSame('web-1, web-2', $data['http.response.header.x-served-by']);
        $this->assertSame([
            'theme=dark',
            'laravel_session=[Filtered]',
            'remember_web_59ba36addc2b2f9401580f014c7f58ea4e30989d=[Filtered]',
        ], $data['http.response.header.set-cookie']);
        $this->assertSame('{"username":"jane","password":"[Filtered]"}', $data['http.response.body.data']);
    }

    public function testRawResponseBodyIsReplaced(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 1.0,
            'sentry.data_collection' => [],
        ]);

        $data = $this->getTransactionDataForResponse(static function () {
            return 'Hello World';
        });

        $this->assertSame('[Filtered]', $data['http.response.body.data']);
    }

    public function testStreamedResponseBodyIsNotCollected(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 1.0,
            'sentry.data_collection' => [],
        ]);

        $data = $this->getTransactionDataForResponse(static function () {
            return new StreamedResponse(static function () {
            });
        });

        $this->assertArrayNotHasKey('http.response.body.data', $data);
    }

    public function testResponseDataIsNotCollectedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 1.0,
            'sentry.data_collection' => [
                'cookies' => ['mode' => 'off'],
                'http_headers' => [
                    'response' => ['mode' => 'off'],
                ],
                'http_bodies' => ['incomingRequest', 'outgoingRequest', 'incomingResponse'],
            ],
        ]);

        $data = $this->getTransactionDataForResponse(static function () {
            $response = new JsonResponse(['username' => 'jane']);

            $response->headers->setCookie(Cookie::create('theme', 'dark'));

            return $response;
        });

        $this->assertSame([], $this->getResponseDataKeys($data));
    }

    public function testResponseDataIsNotCollectedWithoutDataCollection(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 1.0,
            'sentry.send_default_pii' => true,
        ]);

        $data = $this->getTransactionDataForResponse(static function () {
            $response = new JsonResponse(['username' => 'jane']);

            $response->headers->setCookie(Cookie::create('theme', 'dark'));

            return $response;
        });

        $this->assertSame([], $this->getResponseDataKeys($data));
    }

    private function getTransactionDataForResponse(Closure $action): array
    {
        $this->app['router']->get('/response', $action);

        $this->get('/response')->assertOk();

        $transaction = $this->getLastSentryEvent();

        $this->assertNotNull($transaction);
        $this->assertSame(EventType::transaction(), $transaction->getType());

        return $transaction->getContexts()['trace']['data'];
    }

    /**
     * @return string[]
     */
    private function getResponseDataKeys(array $data): array
    {
        return array_values(array_filter(array_keys($data), static function (string $key): bool {
            return strpos($key, 'http.response.header.') === 0 || $key === 'http.response.body.data';
        }));
    }
}
