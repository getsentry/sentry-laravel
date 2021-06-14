<?php

namespace Sentry\Laravel\Http;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Psr\Http\Message\ServerRequestInterface;
use Sentry\State\HubInterface;
use Throwable;

/**
 * This middleware caches a PSR-7 version of the request as early as possible.
 * This is done to prevent running into (mostly uploaded file) parsing failures.
 */
class SetRequestMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $container = Container::getInstance();

        if ($container->bound(HubInterface::class)) {
            try {
                $container->instance(
                    LaravelRequestFetcher::CONTAINER_PSR7_INSTANCE_KEY,
                    $container->make(ServerRequestInterface::class)
                );
            } catch (Throwable $e) {
                // Ignore problems getting the PSR-7 server request instance here
                // In the Laravel request fetcher we have other fallbacks for that
            }
        }

        return $next($request);
    }
}
