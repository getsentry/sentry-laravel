<?php

namespace Sentry\Laravel;

use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
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
        Scope::addGlobalEventProcessor(function (Event $event): Event {
            $self = SentrySdk::getCurrentHub()->getIntegration(self::class);

            if (!$self instanceof self) {
                return $event;
            }

            if (empty($event->getTransaction())) {
                $event->setTransaction($self->getTransaction());
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
     * @return string|null
     */
    public static function extractNameForRoute(Route $route): ?string
    {
        $routeName = null;

        if (empty($routeName) && $route->getName()) {
            // someaction (route name/alias)
            $routeName = $route->getName();

            // Laravel 7 route caching generates a route names if the user didn't specify one
            // theirselfs to optimize route matching. These route names are useless to the
            // developer so if we encounter a generated route name we discard the value
            if (Str::contains($routeName, 'generated::')) {
                $routeName = null;
            }

            // If the route name ends with a `.` we assume an incomplete group name prefix
            // we discard this value since it will most likely not mean anything to the
            // developer and will be duplicated by other unnamed routes in the group
            if (Str::endsWith($routeName, '.')) {
                $routeName = null;
            }
        }

        if (empty($routeName) && $route->getActionName()) {
            // Some\Controller@someAction (controller action)
            $routeName = ltrim($route->getActionName(), '\\');

            $baseNamespace = self::$baseControllerNamespace ?? '';

            // Strip away the base namespace from the action name
            if (!empty($baseNamespace)) {
                // @see: Str::after, but this is not available before Laravel 5.4 so we use a inlined version
                $routeName = array_reverse(explode($baseNamespace . '\\', $routeName, 2))[0];
            }
        }

        if (empty($routeName) || $routeName === 'Closure') {
            // /someaction // Fallback to the url
            $routeName = '/' . ltrim($route->uri(), '/');
        }

        return $routeName;
    }

    /**
     * Retrieve the meta tags with tracing information to link this request to front-end requests.
     *
     * @return string
     */
    public static function sentryTracingMeta(): string
    {
        $span = self::currentTracingSpan();

        if ($span === null) {
            return '';
        }

        $content = sprintf('<meta name="sentry-trace" content="%s"/>', $span->toTraceparent());
        // $content .= sprintf('<meta name="sentry-trace-data" content="%s"/>', $span->getDescription());

        return $content;
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
