<?php

namespace Sentry\Laravel\Tests;

use Sentry\Tracing\Transaction;
use Illuminate\Config\Repository;
use Sentry\Tracing\TransactionContext;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Application;
use ReflectionMethod;
use Sentry\Breadcrumb;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\EventType;
use Sentry\State\Scope;
use ReflectionProperty;
use Sentry\Laravel\Tracing;
use Sentry\State\HubInterface;
use Sentry\Laravel\ServiceProvider;
use Orchestra\Testbench\TestCase as LaravelTestCase;

abstract class TestCase extends LaravelTestCase
{
    private static $hasSetupGlobalEventProcessor = false;

    protected $loadEnvironmentVariables = false;

    protected $setupConfig = [
        // Set config here before refreshing the app to set it in the container before Sentry is loaded
        // or use the `$this->resetApplicationWithConfig([ /* config */ ]);` helper method
    ];

    /** @var array<int, array{0: Event, 1: EventHint|null}> */
    protected static $lastSentryEvents = [];

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        self::$lastSentryEvents = [];

        $this->setupGlobalEventProcessor();

        tap($app['config'], function (Repository $config) {
            // This key has no meaning, it's just a randomly generated one but it's required for the app to boot properly
            $config->set('app.key', 'base64:JfXL2QpYC1+szaw+CdT6SHXG8zjdTkKM/ctPWoTWbXU=');

            $config->set('sentry.before_send', static function (Event $event, ?EventHint $hint) {
                self::$lastSentryEvents[] = [$event, $hint];

                return null;
            });

            $config->set('sentry.before_send_transaction', static function (Event $event, ?EventHint $hint) {
                self::$lastSentryEvents[] = [$event, $hint];

                return null;
            });

            if ($config->get('sentry_test.override_dsn') !== true) {
                $config->set('sentry.dsn', 'https://publickey@sentry.dev/123');
            }

            foreach ($this->setupConfig as $key => $value) {
                $config->set($key, $value);
            }
        });

        $app->extend(ExceptionHandler::class, function (ExceptionHandler $handler) {
            return new TestCaseExceptionHandler($handler);
        });
    }

    /** @param Application $app */
    protected function envWithoutDsnSet($app): void
    {
        $app['config']->set('sentry.dsn', null);
        $app['config']->set('sentry_test.override_dsn', true);
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

    protected function getLastEvent(): ?Event
    {
        if (empty(self::$lastSentryEvents)) {
            return null;
        }

        return end(self::$lastSentryEvents)[0];
    }

    protected function getLastEventHint(): ?EventHint
    {
        if (empty(self::$lastSentryEvents)) {
            return null;
        }

        return end(self::$lastSentryEvents)[1];
    }

    protected function getEventsCount(): int
    {
        return count(self::$lastSentryEvents);
    }

    protected function startTransaction(): Transaction
    {
        $hub = $this->getHubFromContainer();

        $transaction = $hub->startTransaction(new TransactionContext);
        $transaction->initSpanRecorder();

        $this->getCurrentScope()->setSpan($transaction);

        return $transaction;
    }

    private function setupGlobalEventProcessor(): void
    {
        if (self::$hasSetupGlobalEventProcessor) {
            return;
        }

        Scope::addGlobalEventProcessor(static function (Event $event, ?EventHint $hint) {
            // Regular events and transactions are handled by the `before_send` and `before_send_transaction` callbacks
            if (in_array($event->getType(), [EventType::event(), EventType::transaction()], true)) {
                return $event;
            }

            self::$lastSentryEvents[] = [$event, $hint];

            return null;
        });

        self::$hasSetupGlobalEventProcessor = true;
    }
}
