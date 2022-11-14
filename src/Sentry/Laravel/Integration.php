<?php

namespace Sentry\Laravel;

use Illuminate\Routing\Route;
use Sentry\EventHint;
use Sentry\EventId;
use Sentry\ExceptionMechanism;
use Sentry\SentrySdk;
use Sentry\Tracing\TransactionSource;
use Throwable;
use function Sentry\addBreadcrumb;
use function Sentry\captureEvent;
use function Sentry\configureScope;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\Integration\IntegrationInterface;
use Sentry\State\Scope;

class Integration implements IntegrationInterface
{
    /**
     * @var null|string
     */
    private static $transaction;

    /**
     * {@inheritdoc}
     */
    public function setupOnce(): void
    {
        Scope::addGlobalEventProcessor(static function (Event $event): Event {
            $self = SentrySdk::getCurrentHub()->getIntegration(self::class);

            if (!$self instanceof self) {
                return $event;
            }

            if (empty($event->getTransaction())) {
                $event->setTransaction(self::getTransaction());
            }

            return $event;
        });
    }

    /**
     * Adds a breadcrumb if the integration is enabled for Laravel.
     *
     * @param Breadcrumb $breadcrumb
     */
    public static function addBreadcrumb(Breadcrumb $breadcrumb): void
    {
        $self = SentrySdk::getCurrentHub()->getIntegration(self::class);

        if (!$self instanceof self) {
            return;
        }

        addBreadcrumb($breadcrumb);
    }

    /**
     * Configures the scope if the integration is enabled for Laravel.
     *
     * @param callable $callback
     */
    public static function configureScope(callable $callback): void
    {
        $self = SentrySdk::getCurrentHub()->getIntegration(self::class);

        if (!$self instanceof self) {
            return;
        }

        configureScope($callback);
    }

    /**
     * @return null|string
     */
    public static function getTransaction(): ?string
    {
        return self::$transaction;
    }

    /**
     * @param null|string $transaction
     */
    public static function setTransaction(?string $transaction): void
    {
        self::$transaction = $transaction;
    }

    /**
     * Block until all async events are processed for the HTTP transport.
     *
     * @internal This is not part of the public API and is here temporarily until
     *  the underlying issue can be resolved, this method will be removed.
     */
    public static function flushEvents(): void
    {
        $client = SentrySdk::getCurrentHub()->getClient();

        if ($client !== null) {
            $client->flush();
        }
    }

    /**
     * Extract the readable name for a route and the transaction source for where that route name came from.
     *
     * @param \Illuminate\Routing\Route $route
     *
     * @return array{0: string, 1: \Sentry\Tracing\TransactionSource}
     *
     * @internal This helper is used in various places to extra meaninful info from a Laravel Route object.
     */
    public static function extractNameAndSourceForRoute(Route $route): array
    {
        return [
            '/' . ltrim($route->uri(), '/'),
            TransactionSource::route(),
        ];
    }

    /**
     * Retrieve the meta tags with tracing information to link this request to front-end requests.
     * This propagates the Dynamic Sampling Context.
     *
     * @return string
     */
    public static function sentryMeta(): string
    {
        return self::sentryTracingMeta() . self::sentryBaggageMeta();
    }

    /**
     * Retrieve the `sentry-trace` meta tag with tracing information to link this request to front-end requests.
     *
     * @return string
     */
    public static function sentryTracingMeta(): string
    {
        $span = SentrySdk::getCurrentHub()->getSpan();

        if ($span === null) {
            return '';
        }

        return sprintf('<meta name="sentry-trace" content="%s"/>', $span->toTraceparent());
    }

    /**
     * Retrieve the `baggage` meta tag with information to link this request to front-end requests.
     * This propagates the Dynamic Sampling Context.
     *
     * @return string
     */
    public static function sentryBaggageMeta(): string
    {
        $span = SentrySdk::getCurrentHub()->getSpan();

        if ($span === null) {
            return '';
        }

        return sprintf('<meta name="baggage" content="%s"/>', $span->toBaggage());
    }

    /**
     * Capture a unhandled exception and report it to Sentry.
     *
     * @param \Throwable $throwable
     *
     * @return \Sentry\EventId|null
     */
    public static function captureUnhandledException(Throwable $throwable): ?EventId
    {
        $hint = EventHint::fromArray([
            'mechanism' => new ExceptionMechanism(ExceptionMechanism::TYPE_GENERIC, false),
        ]);

        return SentrySdk::getCurrentHub()->captureException($throwable, $hint);
    }
}
