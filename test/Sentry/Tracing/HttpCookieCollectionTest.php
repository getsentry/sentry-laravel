<?php

namespace Sentry\Laravel\Tests\Tracing;

use Illuminate\Http\Request;
use ReflectionProperty;
use Sentry\Laravel\Tests\TestCase;
use Sentry\Laravel\Tracing\Middleware;
use Sentry\Tracing\Transaction;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class HttpCookieCollectionTest extends TestCase
{
    public function testRequestCookiesAreCollectedOnServerTransactions(): void
    {
        $request = Request::create('/cookies', 'GET', [], ['theme' => 'dark']);
        list($middleware, $transaction) = $this->handleRequest($request);

        $middleware->terminate($request, new Response());

        $this->assertSame('dark', $transaction->getData()['http.request.header.cookie.theme']);
    }

    public function testSensitiveRequestCookiesAreFiltered(): void
    {
        $request = Request::create('/cookies', 'GET', [], ['session_id' => 'secret']);
        list($middleware, $transaction) = $this->handleRequest($request);

        $middleware->terminate($request, new Response());

        $this->assertSame('[Filtered]', $transaction->getData()['http.request.header.cookie.session_id']);
    }

    public function testLaravelRememberRequestCookiesRemainFiltered(): void
    {
        $request = Request::create('/cookies', 'GET', [], ['remember_web' => 'secret']);
        list($middleware, $transaction) = $this->handleRequest($request);

        $middleware->terminate($request, new Response());

        $this->assertSame('[Filtered]', $transaction->getData()['http.request.header.cookie.remember_web']);
    }

    public function testResponseCookiesAreCollectedOnServerTransactions(): void
    {
        $request = Request::create('/cookies');
        list($middleware, $transaction) = $this->handleRequest($request);
        $response = $this->responseWithCookie('theme', 'light');

        $middleware->terminate($request, $response);

        $this->assertSame('light', $transaction->getData()['http.response.header.set_cookie.theme']);
    }

    public function testLaravelRememberResponseCookiesRemainFiltered(): void
    {
        $request = Request::create('/cookies');
        list($middleware, $transaction) = $this->handleRequest($request);

        $middleware->terminate($request, $this->responseWithCookie('remember_web', 'secret'));

        $this->assertSame('[Filtered]', $transaction->getData()['http.response.header.set_cookie.remember_web']);
    }

    public function testRepeatedResponseCookieValuesArePreserved(): void
    {
        $request = Request::create('/cookies');
        list($middleware, $transaction) = $this->handleRequest($request);
        $response = new Response();
        $response->headers->setCookie(new Cookie('theme', 'light', 0, '/'));
        $response->headers->setCookie(new Cookie('theme', 'dark', 0, '/admin'));

        $middleware->terminate($request, $response);

        $this->assertSame(['light', 'dark'], $transaction->getData()['http.response.header.set_cookie.theme']);
    }

    public function testResponseCookiesAreCollectedWithoutSerializingThem(): void
    {
        $request = Request::create('/cookies');
        list($middleware, $transaction) = $this->handleRequest($request);
        $response = new Response();
        $response->headers->setCookie(new UnserializableCookie('session_id', 'secret'));

        $middleware->terminate($request, $response);

        $this->assertSame('[Filtered]', $transaction->getData()['http.response.header.set_cookie.session_id']);
    }

    public function testCookieCollectionCanBeTurnedOff(): void
    {
        $this->resetApplicationWithConfig($this->tracingConfig(['mode' => 'off']));
        $request = Request::create('/cookies', 'GET', [], ['theme' => 'dark']);
        list($middleware, $transaction) = $this->handleRequest($request, false);

        $middleware->terminate($request, $this->responseWithCookie('theme', 'light'));

        $this->assertSame([], $this->cookieData($transaction));
    }

    public function testCookieAllowListFiltersUnlistedCookies(): void
    {
        $this->resetApplicationWithConfig($this->tracingConfig([
            'mode' => 'allowList',
            'terms' => ['theme'],
        ]));
        $request = Request::create('/cookies', 'GET', [], ['locale' => 'en']);
        list($middleware, $transaction) = $this->handleRequest($request, false);

        $middleware->terminate($request, new Response());

        $this->assertSame('[Filtered]', $transaction->getData()['http.request.header.cookie.locale']);
    }

    public function testLegacyModeDoesNotAddCookieAttributes(): void
    {
        $this->resetApplicationWithConfig($this->tracingConfig(null));
        $request = Request::create('/cookies', 'GET', [], ['theme' => 'dark']);
        list($middleware, $transaction) = $this->handleRequest($request, false);

        $middleware->terminate($request, $this->responseWithCookie('theme', 'light'));

        $this->assertSame([], $this->cookieData($transaction));
    }

    public function testExplicitResponseCookieDataIsPreserved(): void
    {
        $request = Request::create('/cookies');
        list($middleware, $transaction) = $this->handleRequest($request);
        $transaction->setData(['http.response.header.set_cookie.theme' => null]);

        $middleware->terminate($request, $this->responseWithCookie('theme', 'light'));

        $this->assertNull($transaction->getData()['http.response.header.set_cookie.theme']);
    }

    /**
     * @param array<string, mixed>|null $cookies
     *
     * @return array<string, mixed>
     */
    private function tracingConfig(?array $cookies): array
    {
        return [
            'sentry.traces_sample_rate' => 1.0,
            'sentry.tracing.continue_after_response' => false,
            'sentry.tracing.missing_routes' => true,
            'sentry.data_collection' => $cookies === null ? null : [
                'cookies' => $cookies,
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
            $this->resetApplicationWithConfig($this->tracingConfig([]));
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

    private function responseWithCookie(string $name, string $value): Response
    {
        $response = new Response();
        $response->headers->setCookie(new Cookie($name, $value));

        return $response;
    }

    /** @return array<string, mixed> */
    private function cookieData(Transaction $transaction): array
    {
        return array_filter($transaction->getData(), static function ($key): bool {
            return strpos($key, '.cookie.') !== false || strpos($key, '.set_cookie.') !== false;
        }, \ARRAY_FILTER_USE_KEY);
    }
}

class UnserializableCookie extends Cookie
{
    public function __toString(): string
    {
        throw new \RuntimeException('The cookie must not be serialized while collecting it.');
    }
}
