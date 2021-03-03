<?php

namespace Sentry\Laravel\Http;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use Psr\Http\Message\ServerRequestInterface;
use Sentry\Integration\RequestFetcher;
use Sentry\Integration\RequestFetcherInterface;

class LaravelRequestFetcher implements RequestFetcherInterface
{
    /**
     * The Laravel application container.
     *
     * @var \Illuminate\Contracts\Foundation\Application
     */
    private $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function fetchRequest(): ?ServerRequestInterface
    {
        if (!$this->app->bound('request') || $this->app->runningInConsole()) {
            return null;
        }

        try {
            return $this->app->make(ServerRequestInterface::class);
        } catch (BindingResolutionException $e) {
            return (new RequestFetcher)->fetchRequest();
        }
    }
}
