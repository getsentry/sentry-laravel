<?php

namespace Sentry;

use Sentry\Laravel\Facade;
use Sentry\Laravel\ServiceProvider;

class ServiceProviderWithEnvironmentFromEnvironmentTest extends \Orchestra\Testbench\TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        putenv('SENTRY_ENVIRONMENT=not_testing');
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

    public function testSentryEnvironmentDefaultGetsOverriddenByEnvironmentVariable()
    {
        $this->assertEquals('not_testing', app('sentry')->getClient()->getOptions()->getEnvironment());
    }
}
