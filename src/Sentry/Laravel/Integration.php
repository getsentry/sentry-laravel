<?php

namespace Sentry\Laravel;

use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Sentry\FlushableClientInterface;
use Sentry\SentrySdk;
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
     * {@inheritdoc}
     */
    public function setupOnce(): void
    {
        Scope::addGlobalEventProcessor(function (Event $event): Event {
            $self = SentrySdk::getCurrentHub()->getIntegration(self::class);

            if (!$self instanceof self) {
                return $event;
            }

            if (null === $event->getTransaction()) {
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
    public static function getTransaction()
    {
        return self::$transaction;
    }

    /**
     * @param null|string $transaction
     */
    public static function setTransaction($transaction): void
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

        if ($client instanceof FlushableClientInterface) {
            $client->flush();
        }
    }

    /**
     * Extract the name for the route.
     *
     * @param \Illuminate\Routing\Route $route
     *
     * @return string|null
     */
    public static function extractNameForRoute(Route $route)
    {
        $routeName = null;

        if ($route->getName()) {
            // someaction (route name/alias)
            $routeName = $route->getName();

            // Laravel 7 route caching generates a route names if the user didn't specify one
            // theirselfs to optimize route matching. These route names are useless to the
            // developer so if we encounter a generated route name we discard the value
            if (Str::startsWith($routeName, 'generated::')) {
                $routeName = null;
            }
        }

        if (empty($routeName) && $route->getActionName()) {
            // SomeController@someAction (controller action)
            $routeName = $route->getActionName();
        }

        if (empty($routeName) || $routeName === 'Closure') {
            // /someaction // Fallback to the url
            $routeName = $route->uri();
        }

        return $routeName;
    }
}
