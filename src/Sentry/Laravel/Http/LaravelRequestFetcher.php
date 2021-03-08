<?php

namespace Sentry\Laravel\Http;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Psr\Http\Message\ServerRequestInterface;
use Sentry\Integration\RequestFetcher;
use Sentry\Integration\RequestFetcherInterface;

class LaravelRequestFetcher implements RequestFetcherInterface
{
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

        try {
            return $this->container->make(ServerRequestInterface::class);
        } catch (BindingResolutionException $e) {
            return (new RequestFetcher)->fetchRequest();
        }
    }
}
