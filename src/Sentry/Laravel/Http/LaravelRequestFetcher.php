<?php

namespace Sentry\Laravel\Http;

use Illuminate\Contracts\Container\Container;
use Psr\Http\Message\ServerRequestInterface;
use Sentry\Integration\RequestFetcher;
use Sentry\Integration\RequestFetcherInterface;

class LaravelRequestFetcher implements RequestFetcherInterface
{
    /**
     * They key in the container where a PSR-7 instance of the current request could be stored.
     */
    public const CONTAINER_PSR7_INSTANCE_KEY = 'sentry-laravel.psr7.request';

    /**
     * The Laravel container.
     *
     * @var \Illuminate\Contracts\Container\Container
     */
    private $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function fetchRequest(): ?ServerRequestInterface
    {
        if (\PHP_SAPI === 'cli' || \PHP_SAPI === 'phpdbg' || !$this->container->bound('request')) {
            return null;
        }

        if ($this->container->bound(self::CONTAINER_PSR7_INSTANCE_KEY)) {
            return $this->container->make(self::CONTAINER_PSR7_INSTANCE_KEY);
        }

        return (new RequestFetcher)->fetchRequest();
    }
}
