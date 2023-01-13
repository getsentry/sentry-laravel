<?php

namespace Sentry\Laravel\Tests;

use Illuminate\Contracts\Debug\ExceptionHandler;
use ReflectionMethod;
use Sentry\Breadcrumb;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Laravel\Integration;
use Sentry\State\Scope;
use ReflectionProperty;
use Sentry\Laravel\Tracing;
use Sentry\State\HubInterface;
use Sentry\Laravel\ServiceProvider;
use Orchestra\Testbench\TestCase as LaravelTestCase;
use Throwable;

abstract class TestCase extends LaravelTestCase
{
    protected $setupConfig = [
        // Set config here before refreshing the app to set it in the container before Sentry is loaded
        // or use the `$this->resetApplicationWithConfig([ /* config */ ]);` helper method
    ];

    /** @var array<int, array{0: Event, 1: EventHint}> */
    protected $lastSentryEvents = [];

    protected function getEnvironmentSetUp($app): void
    {
        $this->lastSentryEvents = [];

        $app['config']->set('sentry.before_send', function (Event $event, EventHint $hint) {
            $this->lastSentryEvents[] = [$event, $hint];

            return null;
        });

        $app['config']->set('sentry.dsn', 'http://publickey:secretkey@sentry.dev/123');

        foreach ($this->setupConfig as $key => $value) {
            $app['config']->set($key, $value);
        }

        $app->extend(ExceptionHandler::class, function (ExceptionHandler $handler) {
            return new TestCaseExceptionHandler($handler);
        });
    }

    protected function getPackageProviders($app): array
    {
        return [
            ServiceProvider::class,
            Tracing\ServiceProvider::class,
        ];
    }

    protected function resetApplicationWithConfig(array $config): void
    {
        $this->setupConfig = $config;

        $this->refreshApplication();
    }

    protected function dispatchLaravelEvent($event, array $payload = []): void
    {
        $this->app['events']->dispatch($event, $payload);
    }

    protected function getHubFromContainer(): HubInterface
    {
        return $this->app->make('sentry');
    }

    protected function getClientFromContainer(): ClientInterface
    {
        return $this->getHubFromContainer()->getClient();
    }

    protected function getCurrentScope(): Scope
    {
        $hub = $this->getHubFromContainer();

        $method = new ReflectionMethod($hub, 'getScope');
        $method->setAccessible(true);

        return $method->invoke($hub);
    }

    /** @return array<array-key, \Sentry\Breadcrumb> */
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
