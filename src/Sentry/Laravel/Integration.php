<?php

namespace Sentry\Laravel;

use Sentry\FlushableClientInterface;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
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
            $self = static::getCurrentHub()->getIntegration(self::class);

            if (!$self instanceof self) {
                return $event;
            }

            $event->setTransaction($self->getTransaction());

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
        $self = static::getCurrentHub()->getIntegration(self::class);

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
        $self = static::getCurrentHub()->getIntegration(self::class);

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
        $client = static::getCurrentHub()->getClient();

        if ($client instanceof FlushableClientInterface) {
            $client->flush();
        }
    }

    /**
     * Gets the current hub. If it's not initialized then creates a new instance
     * and sets it as current hub.
     *
     * The is here for legacy reasons where we used the Hub directly as a singleton.
     *
     * @TODO: This method should be removed and replaced with calls to `SentrySdk::getCurrentHub()` directly once
     *   `sentry/sentry` 3.0 is released and pinned as an dependency.
     *
     * @internal This is not part of the public API and is here temporarily.
     *
     * @return \Sentry\State\HubInterface
     */
    public static function getCurrentHub(): HubInterface
    {
        if (class_exists(SentrySdk::class)) {
            SentrySdk::getCurrentHub();
        }

        return Hub::getCurrent();
    }

    /**
     * Sets the current hub.
     *
     * The is here for legacy reasons where we used the Hub directly as a singleton.
     *
     * @TODO: This method should be removed and replaced with calls to `SentrySdk::getCurrentHub()` directly once
     *   `sentry/sentry` 3.0 is released and pinned as an dependency.
     *
     * @internal This is not part of the public API and is here temporarily.
     *
     * @param \Sentry\State\HubInterface $hub
     *
     * @return void
     */
    public static function setCurrentHub(HubInterface $hub): void
    {
        if (class_exists(SentrySdk::class)) {
            SentrySdk::setCurrentHub($hub);

            return;
        }

        Hub::setCurrent($hub);
    }
}
