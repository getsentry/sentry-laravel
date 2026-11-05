<?php

namespace Sentry\Laravel\Tests;

use Sentry\Tracing\Transaction;
use Illuminate\Config\Repository;
use Sentry\Tracing\TransactionContext;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Application;
use Sentry\Breadcrumb;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\EventType;
use Sentry\SentrySdk;
use Sentry\State\IsolationScope;
use Sentry\Laravel\Tracing;
use Sentry\Laravel\SentryManager;
use Sentry\Laravel\ServiceProvider;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Orchestra\Testbench\TestCase as LaravelTestCase;

abstract class TestCase extends LaravelTestCase
{
    protected $loadEnvironmentVariables = false;

    protected $setupConfig = [
        // Set config here before refreshing the app to set it in the container before Sentry is loaded
        // or use the `$this->resetApplicationWithConfig([ /* config */ ]);` helper method
    ];

    protected $defaultSetupConfig = [];

    /** @var array<int, array{0: Event, 1: EventHint|null}> */
    protected static $lastSentryEvents = [];

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        self::$lastSentryEvents = [];

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

            // Regular events and transactions are handled by the `before_send` and `before_send_transaction` callbacks,
            // the transport records everything else that is sent, like check-ins and logs
            $config->set('sentry.transport', new class(static function (Event $event): void {
                if (!in_array($event->getType(), [EventType::event(), EventType::transaction()], true)) {
                    self::$lastSentryEvents[] = [$event, null];
                }
            }) implements TransportInterface {
                /** @var callable */
                private $recorder;

                public function __construct(callable $recorder)
                {
                    $this->recorder = $recorder;
                }

                public function send(Event $event): Result
                {
                    ($this->recorder)($event);

                    return new Result(ResultStatus::success(), $event);
                }

                public function close(?int $timeout = null): Result
                {
                    return new Result(ResultStatus::success());
                }
            });

            if ($config->get('sentry_test.override_dsn') !== true) {
                $config->set('sentry.dsn', 'https://publickey@sentry.dev/123');
            }

            foreach ($this->defaultSetupConfig as $key => $value) {
                $config->set($key, $value);
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

    /** @param Application $app */
    protected function envSamplingAllTransactions($app): void
    {
        $app['config']->set('sentry.traces_sample_rate', 1.0);
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

    protected function getSentryFromContainer(): SentryManager
    {
        return $this->app->make('sentry');
    }

    protected function getSentryClientFromContainer(): ClientInterface
    {
        return $this->getSentryFromContainer()->getClient();
    }

    protected function getCurrentSentryScope(): IsolationScope
    {
        return SentrySdk::getIsolationScope();
    }

    protected function applyCurrentSentryScopesToEvent(Event $event, ?EventHint $hint = null): ?Event
    {
        return SentrySdk::getGlobalScope()
            ->merge($this->getCurrentSentryScope())
            ->applyToEvent($event, $hint, $this->getSentryClientFromContainer()->getOptions());
    }

    /** @return array<array-key, \Sentry\Breadcrumb> */
    protected function getCurrentSentryBreadcrumbs(): array
    {
        return $this->getCurrentSentryScope()->getBreadcrumbs();
    }

    protected function getLastSentryBreadcrumb(): ?Breadcrumb
    {
        $breadcrumbs = $this->getCurrentSentryBreadcrumbs();

        if (empty($breadcrumbs)) {
            return null;
        }

        return end($breadcrumbs);
    }

    protected function getLastSentryEvent(): ?Event
    {
        if (empty(self::$lastSentryEvents)) {
            return null;
        }

        return end(self::$lastSentryEvents)[0];
    }

    protected function getLastEventSentryHint(): ?EventHint
    {
        if (empty(self::$lastSentryEvents)) {
            return null;
        }

        return end(self::$lastSentryEvents)[1];
    }

    /** @return array<int, array{0: Event, 1: EventHint|null}> */
    protected function getCapturedSentryEvents(): array
    {
        return self::$lastSentryEvents;
    }

    protected function assertSentryEventCount(int $count): void
    {
        $this->assertCount($count, array_filter(self::$lastSentryEvents, static function (array $event) {
            return $event[0]->getType() === EventType::event();
        }));
    }

    protected function assertSentryCheckInCount(int $count): void
    {
        $this->assertCount($count, array_filter(self::$lastSentryEvents, static function (array $event) {
            return $event[0]->getType() === EventType::checkIn();
        }));
    }

    protected function assertSentryTransactionCount(int $count): void
    {
        $this->assertCount($count, array_filter(self::$lastSentryEvents, static function (array $event) {
            return $event[0]->getType() === EventType::transaction();
        }));
    }

    protected function startTransaction(): Transaction
    {
        $transaction = $this->getSentryFromContainer()->startTransaction(new TransactionContext);
        $transaction->initSpanRecorder();
        $transaction->setSampled(true);

        $this->getCurrentSentryScope()->setSpan($transaction);

        return $transaction;
    }
}
