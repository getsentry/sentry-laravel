<?php

namespace Sentry\Laravel\Http;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Sentry\State\HubInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

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
            $psrRequest = $this->resolvePsrRequest($request);

            if ($psrRequest !== null) {
                $container->instance(LaravelRequestFetcher::CONTAINER_PSR7_INSTANCE_KEY, $psrRequest);
            }
        }

        return $next($request);
    }

    /**
     * This code was copied from the Laravel codebase which was introduced in Laravel 6.
     *
     * The reason we have it copied here is because older (<6.0) versions of Laravel use a different
     * method to construct the PSR-7 request object which requires other packages to create that object
     * but most importantly it does not function when those packages are not available resulting in errors
     *
     * So long story short, this is here to backport functionality to Laravel <6.0
     * if we drop support for those versions in the future we can reconsider this and
     * move back to using the container binding provided by Laravel for the PSR-7 object
     *
     * @see https://github.com/laravel/framework/blob/cb550b5bdc2b2c4cf077082adabde0144a72d190/src/Illuminate/Routing/RoutingServiceProvider.php#L127-L146
     */
    private function resolvePsrRequest(Request $request): ?ServerRequestInterface
    {
        if (class_exists(Psr17Factory::class) && class_exists(PsrHttpFactory::class)) {
            $psr17Factory = new Psr17Factory;

            return (new PsrHttpFactory($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory))
                ->createRequest($request);
        }

        return null;
    }
}
