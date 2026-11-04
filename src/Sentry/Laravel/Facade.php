<?php

namespace Sentry\Laravel;

/**
 * @see \Sentry\Laravel\SentryManager
 *
 * @method static \Sentry\ClientInterface getClient()
 * @method static void bindClient(\Sentry\ClientInterface $client)
 * @method static \Sentry\State\GlobalScope getGlobalScope()
 * @method static \Sentry\State\IsolationScope getIsolationScope()
 * @method static \Sentry\EventId|null getLastEventId()
 * @method static \Sentry\State\IsolationScope pushScope()
 * @method static bool popScope()
 * @method static mixed withScope(callable $callback)
 * @method static mixed withIsolationScope(callable $callback)
 * @method static void configureScope(callable $callback)
 * @method static \Sentry\EventId|null captureMessage(string $message, \Sentry\Severity|null $level = null, \Sentry\EventHint|null $hint = null)
 * @method static \Sentry\EventId|null captureException(\Throwable $exception, \Sentry\EventHint|null $hint = null)
 * @method static \Sentry\EventId|null captureEvent(\Sentry\Event $event, \Sentry\EventHint|null $hint = null)
 * @method static \Sentry\EventId|null captureLastError(\Sentry\EventHint|null $hint = null)
 * @method static string|null captureCheckIn(string $slug, \Sentry\CheckInStatus $status, int|float|null $duration = null, \Sentry\MonitorConfig|null $monitorConfig = null, string|null $checkInId = null)
 * @method static void addBreadcrumb(\Sentry\Breadcrumb $breadcrumb)
 * @method static \Sentry\Integration\IntegrationInterface|null getIntegration(string $className)
 * @method static \Sentry\Tracing\Transaction startTransaction(\Sentry\Tracing\TransactionContext $context, array $customSamplingContext = [])
 * @method static \Sentry\Tracing\Transaction|null getTransaction()
 * @method static \Sentry\Tracing\Span|null getSpan()
 * @method static \Sentry\Laravel\SentryManager setSpan(\Sentry\Tracing\Span|null $span)
 */
class Facade extends \Illuminate\Support\Facades\Facade
{
    protected static function getFacadeAccessor()
    {
        return SentryManager::class;
    }
}
