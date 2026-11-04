<?php

namespace Sentry\Laravel;

use Sentry\Breadcrumb;
use Sentry\CheckInStatus;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\EventId;
use Sentry\Integration\IntegrationInterface;
use Sentry\MonitorConfig;
use Sentry\SentrySdk;
use Sentry\Severity;
use Sentry\State\GlobalScope;
use Sentry\State\IsolationScope;
use Sentry\Tracing\Span;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

use function Sentry\addBreadcrumb;
use function Sentry\captureCheckIn;
use function Sentry\captureEvent;
use function Sentry\captureException;
use function Sentry\captureLastError;
use function Sentry\captureMessage;
use function Sentry\configureScope;
use function Sentry\startTransaction;
use function Sentry\withIsolationScope;

/**
 * The instance behind the `sentry` container binding and the Sentry facade.
 *
 * Every method forwards to the SDK state in `SentrySdk`, so `app('sentry')`, the facade
 * and the global `\Sentry\*` functions all work on the same scopes.
 */
class SentryManager
{
    public function getClient(): ClientInterface
    {
        return SentrySdk::getClient();
    }

    public function bindClient(ClientInterface $client): void
    {
        SentrySdk::getGlobalScope()->setClient($client);
    }

    public function getGlobalScope(): GlobalScope
    {
        return SentrySdk::getGlobalScope();
    }

    public function getIsolationScope(): IsolationScope
    {
        return SentrySdk::getIsolationScope();
    }

    public function getLastEventId(): ?EventId
    {
        return SentrySdk::getLastEventId();
    }

    /**
     * Forks the current isolation scope until `popScope()` is called.
     */
    public function pushScope(): IsolationScope
    {
        return SentrySdk::pushIsolationScope();
    }

    /**
     * Restores the isolation scope that was active before the last `pushScope()` call.
     *
     * @return bool Whether there was a pushed scope to pop
     */
    public function popScope(): bool
    {
        return SentrySdk::popIsolationScope();
    }

    /**
     * Runs the callback with a fork of the current isolation scope.
     *
     * @return mixed The callback's return value
     */
    public function withScope(callable $callback)
    {
        return withIsolationScope($callback);
    }

    /**
     * Runs the callback with a fork of the current isolation scope.
     *
     * @return mixed The callback's return value
     */
    public function withIsolationScope(callable $callback)
    {
        return withIsolationScope($callback);
    }

    public function configureScope(callable $callback): void
    {
        configureScope($callback);
    }

    public function captureMessage(string $message, ?Severity $level = null, ?EventHint $hint = null): ?EventId
    {
        return captureMessage($message, $level, $hint);
    }

    public function captureException(\Throwable $exception, ?EventHint $hint = null): ?EventId
    {
        return captureException($exception, $hint);
    }

    public function captureEvent(Event $event, ?EventHint $hint = null): ?EventId
    {
        return captureEvent($event, $hint);
    }

    public function captureLastError(?EventHint $hint = null): ?EventId
    {
        return captureLastError($hint);
    }

    /**
     * @param int|float|null $duration
     */
    public function captureCheckIn(string $slug, CheckInStatus $status, $duration = null, ?MonitorConfig $monitorConfig = null, ?string $checkInId = null): ?string
    {
        return captureCheckIn($slug, $status, $duration, $monitorConfig, $checkInId);
    }

    public function addBreadcrumb(Breadcrumb $breadcrumb): void
    {
        addBreadcrumb($breadcrumb);
    }

    /**
     * @param class-string<IntegrationInterface> $className
     */
    public function getIntegration(string $className): ?IntegrationInterface
    {
        return SentrySdk::getClient()->getIntegration($className);
    }

    /**
     * @param array<string, mixed> $customSamplingContext
     */
    public function startTransaction(TransactionContext $context, array $customSamplingContext = []): Transaction
    {
        return startTransaction($context, $customSamplingContext);
    }

    public function getTransaction(): ?Transaction
    {
        return SentrySdk::getIsolationScope()->getTransaction();
    }

    public function getSpan(): ?Span
    {
        return SentrySdk::getIsolationScope()->getSpan();
    }

    /**
     * @return $this
     */
    public function setSpan(?Span $span): self
    {
        SentrySdk::getIsolationScope()->setSpan($span);

        return $this;
    }
}
