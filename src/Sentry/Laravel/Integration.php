<?php

namespace Sentry\Laravel;

use function Sentry\addBreadcrumb;
use function Sentry\configureScope;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\Client;
use Sentry\Integration\IntegrationInterface;
use Sentry\State\Hub;
use Sentry\State\Scope;
use Sentry\Transport\HttpTransport;

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
            $self = Hub::getCurrent()->getIntegration(self::class);

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
        $self = Hub::getCurrent()->getIntegration(self::class);

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
        $self = Hub::getCurrent()->getIntegration(self::class);

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
        $client = Hub::getCurrent()->getClient();

        if ($client instanceof Client) {
            $transportProperty = new \ReflectionProperty(Client::class, 'transport');
            $transportProperty->setAccessible(true);

            $transport = $transportProperty->getValue($client);

            if ($transport instanceof HttpTransport) {
                $closure = \Closure::bind(function () {
                    $this->cleanupPendingRequests();
                }, $transport, $transport);

                $closure();
            }
        }
    }
}
