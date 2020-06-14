<?php

namespace Sentry;

use Sentry\Laravel\Facade;
use Sentry\Laravel\ServiceProvider;

class ServiceProviderWithEnvironmentFromConfigTest extends \Orchestra\Testbench\TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('sentry.environment', 'not_testing');
    }

    protected function getPackageProviders($app)
    {
        return [
            ServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app)
    {
        return [
            'Sentry' => Facade::class,
        ];
    }

    public function testSentryEnvironmentDefaultGetsOverriddenByConfig()
    {
        $this->assertEquals('not_testing', app('sentry')->getClient()->getOptions()->getEnvironment());
    }
}
