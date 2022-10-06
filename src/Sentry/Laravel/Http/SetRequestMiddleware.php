<?php

namespace Sentry\Laravel\Http;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\Request;
use Psr\Http\Message\ServerRequestInterface;
use Sentry\State\HubInterface;

/**
 * This middleware caches a PSR-7 version of the request as early as possible.
 * This is done to prevent running into (mostly uploaded file) parsing failures.
 */
class SetRequestMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $container = Container::getInstance();

        if ($container->bound(HubInterface::class)) {
            $psrRequest = $this->resolvePsrRequest($container);

            if ($psrRequest !== null) {
                $container->instance(LaravelRequestFetcher::CONTAINER_PSR7_INSTANCE_KEY, $psrRequest);
            }
        }

        return $next($request);
    }

    private function resolvePsrRequest(Container $container): ?ServerRequestInterface
    {
        try {
            return $container->make(ServerRequestInterface::class);
        } catch (BindingResolutionException $e) {
            // This happens if Laravel doesn't have the correct classes available to construct the PSR-7 object
        }

        return null;
    }
}
