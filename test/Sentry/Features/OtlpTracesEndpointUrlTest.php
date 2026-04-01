<?php

declare(strict_types=1);

namespace Sentry\Laravel\Tests\Features;

use Sentry\Integration\OTLPIntegration;
use Sentry\Laravel\Tests\TestCase;

use function Sentry\getOtlpTracesEndpointUrl;

class OtlpTracesEndpointUrlTest extends TestCase
{
    /** @param \Illuminate\Foundation\Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('sentry.integrations', [
            OTLPIntegration::class,
        ]);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/sentry-test/otlp-traces-endpoint-url', function () {
            return response()->json([
                'url' => getOtlpTracesEndpointUrl(),
            ]);
        });
    }

    public function testReturnsDsnDerivedOtlpTracesEndpointUrlDuringHttpRequest(): void
    {
        $dsn = $this->getSentryClientFromContainer()->getOptions()->getDsn();
        $this->assertNotNull($dsn);
        $this->assertNotNull($this->getSentryClientFromContainer()->getIntegration(OTLPIntegration::class));

        $response = $this->get('/sentry-test/otlp-traces-endpoint-url');

        $response->assertOk();
        $response->assertJson([
            'url' => $dsn->getOtlpTracesEndpointUrl(),
        ]);
    }
}
