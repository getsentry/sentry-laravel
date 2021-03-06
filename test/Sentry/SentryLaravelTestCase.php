<?php

namespace Sentry\Laravel\Tests;

use ReflectionMethod;
use Sentry\Breadcrumb;
use Sentry\State\Scope;
use ReflectionProperty;
use Sentry\Laravel\Tracing;
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
            Tracing\ServiceProvider::class,
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

    protected function getHubFromContainer(): HubInterface
    {
        return $this->app->make('sentry');
    }

    protected function getCurrentScope(): Scope
    {
        $hub = $this->getHubFromContainer();

        $method = new ReflectionMethod($hub, 'getScope');
        $method->setAccessible(true);

        return $method->invoke($hub);
    }

    protected function getCurrentBreadcrumbs(): array
    {
        $scope = $this->getCurrentScope();

        $property = new ReflectionProperty($scope, 'breadcrumbs');
        $property->setAccessible(true);

        return $property->getValue($scope);
    }

    protected function getLastBreadcrumb(): ?Breadcrumb
    {
        $breadcrumbs = $this->getCurrentBreadcrumbs();

        if (empty($breadcrumbs)) {
            return null;
        }

        return end($breadcrumbs);
    }
}
