<?php

declare(strict_types=1);

namespace Sentry\Laravel\Tests;

use Exception;
use Monolog\Logger;
use Orchestra\Testbench\TestCase;
use Sentry\Breadcrumb;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\EventId;
use Sentry\Integration\IntegrationInterface;
use Sentry\Laravel\SentryHandler;
use Sentry\Laravel\ServiceProvider;
use Sentry\Severity;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\Tracing\Span;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

class SentryHandlerTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            ServiceProvider::class,
        ];
    }

    public function testHandlerCanBeInstantiatedWithHubInterface()
    {
        $hub = new class implements HubInterface {
            /**
             * @psalm-return no-return
             */
            protected function unimplemented()
            {
                throw new Exception('Unimplemented');
            }

            public function getClient(): ?ClientInterface
            {
                $this->unimplemented();
            }

            public function getLastEventId(): ?EventId
            {
                $this->unimplemented();
            }

            public function pushScope(): Scope
            {
                $this->unimplemented();
            }

            public function popScope(): bool
            {
                $this->unimplemented();
            }

            public function withScope(callable $callback): void
            {
                $this->unimplemented();
            }

            public function configureScope(callable $callback): void
            {
                $this->unimplemented();
            }

            public function bindClient(ClientInterface $client): void
            {
                $this->unimplemented();
            }

            public function captureMessage(string $message, ?Severity $level = null): ?EventId
            {
                $this->unimplemented();
            }

            public function captureException(\Throwable $exception): ?EventId
            {
                $this->unimplemented();
            }

            public function captureEvent(Event $event, ?EventHint $hint = null): ?EventId
            {
                $this->unimplemented();
            }

            public function captureLastError(): ?EventId
            {
                $this->unimplemented();
            }

            public function addBreadcrumb(Breadcrumb $breadcrumb): bool
            {
                $this->unimplemented();
            }

            public function getIntegration(string $className): ?IntegrationInterface
            {
                $this->unimplemented();
            }

            public function startTransaction(TransactionContext $context): Transaction
            {
                $this->unimplemented();
            }

            public function getTransaction(): ?Transaction
            {
                $this->unimplemented();
            }

            public function getSpan(): ?Span
            {
                $this->unimplemented();
            }

            public function setSpan(?Span $span): HubInterface
            {
                $this->unimplemented();
            }
        };

        $this->app->singleton(HubInterface::class, function() use ($hub) {
            return $hub;
        });

        /** @var HubInterface $hub */
        $hub = app('sentry');
        try {
            $hub->captureMessage('test');
            self::fail('Method has not been called');
        } catch (Exception $e) {
            self::assertSame('Unimplemented', $e->getMessage());
        }
    }
}
