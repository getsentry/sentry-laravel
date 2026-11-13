<?php

namespace Sentry\Laravel\Tests\Features;

use Illuminate\Routing\Router;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Sentry\Laravel\Tests\TestCase;

class RouteIntegrationTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->group(['prefix' => 'sentry'], function (Router $router) {
            $router->get('/ok', function () {
                return 'ok';
            });

            $router->get('/abort/{code}', function (int $code) {
                abort($code);
            });
        });

        foreach (['/horizon/api/stats', '/telescope/{view?}', '/_debugbar/open', '/horizontal'] as $uri) {
            $router->get($uri, function () {
                return 'ok';
            });
        }
    }

    /**
     * @define-env envSamplingAllTransactions
     *
     * @dataProvider developmentToolRouteDataProvider
     */
    #[DefineEnvironment('envSamplingAllTransactions')]
    public function testTransactionIsDroppedForDevelopmentToolRoutesByDefault(string $uri, int $expectedTransactionCount): void
    {
        $this->get($uri)->assertOk();

        $this->assertSentryTransactionCount($expectedTransactionCount);
    }

    public static function developmentToolRouteDataProvider(): iterable
    {
        yield 'horizon' => ['/horizon/api/stats', 0];
        yield 'telescope' => ['/telescope', 0];
        yield 'debugbar' => ['/_debugbar/open', 0];
        yield 'route only sharing the start of its name' => ['/horizontal', 1];
    }

    /** @param \Illuminate\Foundation\Application $app */
    protected function envSamplingAllTransactionsWithoutIgnoredStatusCodes($app): void
    {
        $this->envSamplingAllTransactions($app);

        $app['config']->set('sentry.trace_ignore_status_codes', []);
    }

    /** @define-env envSamplingAllTransactions */
    #[DefineEnvironment('envSamplingAllTransactions')]
    public function testTransactionIsRecordedForRoute(): void
    {
        $this->get('/sentry/ok')->assertOk();

        $this->assertSentryTransactionCount(1);
    }

    /** @define-env envSamplingAllTransactions */
    #[DefineEnvironment('envSamplingAllTransactions')]
    public function testTransactionIsDroppedForNotFoundByDefault(): void
    {
        $this->get('/sentry/abort/404')->assertNotFound();

        $this->assertSentryTransactionCount(0);
    }

    /** @define-env envSamplingAllTransactionsWithoutIgnoredStatusCodes */
    #[DefineEnvironment('envSamplingAllTransactionsWithoutIgnoredStatusCodes')]
    public function testTransactionIsRecordedForNotFoundWhenStatusCodeIsNotIgnored(): void
    {
        $this->get('/sentry/abort/404')->assertNotFound();

        $this->assertSentryTransactionCount(1);
    }

    /** @define-env envSamplingAllTransactionsWithoutIgnoredStatusCodes */
    #[DefineEnvironment('envSamplingAllTransactionsWithoutIgnoredStatusCodes')]
    public function testTransactionIsDroppedForUndefinedRouteWhenStatusCodeIsNotIgnored(): void
    {
        $this->get('/sentry/non-existent-route')->assertNotFound();

        $this->assertSentryTransactionCount(0);
    }

    /** @define-env envSamplingAllTransactions */
    #[DefineEnvironment('envSamplingAllTransactions')]
    public function testTransactionIsDroppedForUndefinedRoute(): void
    {
        $this->get('/sentry/non-existent-route')->assertNotFound();

        $this->assertSentryTransactionCount(0);
    }
}
