<?php

namespace Sentry\Laravel;

use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\TransactionSource;
use function Sentry\addBreadcrumb;
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
     * @var null|string
     */
    private static $baseControllerNamespace;

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
     * @param null|string $namespace
     */
    public static function setControllersBaseNamespace(?string $namespace): void
    {
        self::$baseControllerNamespace = $namespace !== null ? trim($namespace, '\\') : null;
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
     * Extract the readable name for a route.
     *
     * @param \Illuminate\Routing\Route $route
     *
     * @return string
     *
     * @internal   This helper is used in various places to extra meaninful info from a Laravel Route object.
     * @deprecated This will be removed in version 3.0, use `extractNameAndSourceForRoute` instead.
     */
    public static function extractNameForRoute(Route $route): string
    {
        return self::extractNameAndSourceForRoute($route)[0];
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
        $source = null;
        $routeName = null;

        // some.action (route name/alias)
        if ($route->getName()) {
            $source = TransactionSource::component();
            $routeName = self::extractNameForNamedRoute($route->getName());
        }

        // Some\Controller@someAction (controller action)
        if (empty($routeName) && $route->getActionName()) {
            $source = TransactionSource::component();
            $routeName = self::extractNameForActionRoute($route->getActionName());
        }

        // /some/{action} // Fallback to the route uri (with parameter placeholders)
        if (empty($routeName) || $routeName === 'Closure') {
            $source = TransactionSource::route();
            $routeName = '/' . ltrim($route->uri(), '/');
        }

        return [$routeName, $source];
    }

    /**
     * Take a route name and return it only if it's a usable route name.
     *
     * @param string $name
     *
     * @return string|null
     */
    private static function extractNameForNamedRoute(string $name): ?string
    {
        // Laravel 7 route caching generates a route names if the user didn't specify one
        // theirselfs to optimize route matching. These route names are useless to the
        // developer so if we encounter a generated route name we discard the value
        if (Str::contains($name, 'generated::')) {
            return null;
        }

        // If the route name ends with a `.` we assume an incomplete group name prefix
        // we discard this value since it will most likely not mean anything to the
        // developer and will be duplicated by other unnamed routes in the group
        if (Str::endsWith($name, '.')) {
            return null;
        }

        return $name;
    }

    /**
     * Take a controller action and strip away the base namespace if needed.
     *
     * @param string $action
     *
     * @return string
     */
    private static function extractNameForActionRoute(string $action): string
    {
        $routeName = ltrim($action, '\\');

        $baseNamespace = self::$baseControllerNamespace ?? '';

        if (empty($baseNamespace)) {
            return $routeName;
        }

        // Strip away the base namespace from the action name
        return ltrim(Str::after($routeName, $baseNamespace), '\\');
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
        $span = self::currentTracingSpan();

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
        $span = self::currentTracingSpan();

        if ($span === null) {
            return '';
        }

        return sprintf('<meta name="baggage" content="%s"/>', $span->toBaggage());
    }

    /**
     * Get the current active tracing span from the scope.
     *
     * @return \Sentry\Tracing\Span|null
     *
     * @internal This is used internally as an easy way to retrieve the current active tracing span.
     */
    public static function currentTracingSpan(): ?Span
    {
        return SentrySdk::getCurrentHub()->getSpan();
    }
}
