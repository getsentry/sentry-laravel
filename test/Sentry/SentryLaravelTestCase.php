<?php

namespace Sentry\Laravel\Tests;

use Sentry\State\Scope;
use Sentry\State\HubInterface;
use Sentry\Laravel\ServiceProvider;
use Orchestra\Testbench\TestCase as LaravelTestCase;

abstract class SentryLaravelTestCase extends LaravelTestCase
{
    protected $setupConfig = [
        // Set config here before refreshing the app to set it in the container before Sentry is loaded
        // or use the `$this->resetApplicationWithConfig([ /* config */ ]);` helper method
    ];

    protected function getEnvironmentSetUp($app)
    {
        foreach ($this->setupConfig as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    protected function getPackageProviders($app)
    {
        return [
            ServiceProvider::class,
        ];
    }

    protected function resetApplicationWithConfig(array $config)
    {
        $this->setupConfig = $config;

        $this->refreshApplication();
    }

    protected function getScope(HubInterface $hub): Scope
    {
        $method = new \ReflectionMethod($hub, 'getScope');

        $method->setAccessible(true);

        return $method->invoke($hub);
    }
}
