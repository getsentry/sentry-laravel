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
        $app['config']->set('sentry.dsn', 'http://publickey:secretkey@sentry.dev/123');

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

    protected function dispatchLaravelEvent($event, array $payload = [])
    {
        $dispatcher = $this->app['events'];

        // Laravel 5.4+ uses the dispatch method to dispatch/fire events
        return method_exists($dispatcher, 'dispatch')
            ? $dispatcher->dispatch($event, $payload)
            : $dispatcher->fire($event, $payload);
    }

    protected function getScope(HubInterface $hub): Scope
    {
        $method = new \ReflectionMethod($hub, 'getScope');

        $method->setAccessible(true);

        return $method->invoke($hub);
    }
}
