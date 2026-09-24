<?php

namespace Sentry\Laravel\Http;

use Illuminate\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Psr\Http\Message\ServerRequestInterface;
use Sentry\DataCollection\DataCollectionPolicy;
use Sentry\Integration\RequestFetcher;
use Sentry\Integration\RequestFetcherInterface;
use Sentry\SentrySdk;

class LaravelRequestFetcher implements RequestFetcherInterface
{
    /**
     * They key in the container where a PSR-7 instance of the current request could be stored.
     */
    public const CONTAINER_PSR7_INSTANCE_KEY = 'sentry-laravel.psr7.request';

    public function fetchRequest(): ?ServerRequestInterface
    {
        $container = Container::getInstance();

        // If there is no request bound to the container
        // we are not dealing with a HTTP request and there
        // is no request to fetch for us so we can exit early.
        if (!$container->bound('request')) {
            return null;
        }

        if ($container->bound(self::CONTAINER_PSR7_INSTANCE_KEY)) {
            $request = $container->make(self::CONTAINER_PSR7_INSTANCE_KEY);
        } else {
            $request = (new RequestFetcher)->fetchRequest();
        }

        if ($request === null) {
            return null;
        }

        $request = $this->withoutEmptyParsedBody($request);

        if (!DataCollectionPolicy::fromHub(SentrySdk::getCurrentHub())->isLegacyMode()) {
            $request = $this->withRawQueryString($request);
        }

        $cookies = new Collection($request->getCookieParams());

        // We need to filter out the cookies that are not allowed to be sent to Sentry because they are very sensitive
        $forbiddenCookies = [config('session.cookie'), 'remember_*', 'XSRF-TOKEN'];

        return $request->withCookieParams(
            $cookies->map(function ($value, string $key) use ($forbiddenCookies) {
                if (Str::is($forbiddenCookies, $key)) {
                    return '[Filtered]';
                }

                return $value;
            })->all()
        );
    }

    /**
     * The PSR-7 request has an empty parsed body for all requests that are not forms, which would
     * hide the raw body from being read.
     */
    private function withoutEmptyParsedBody(ServerRequestInterface $request): ServerRequestInterface
    {
        if ($request->getParsedBody() !== [] || $this->isFormRequest($request)) {
            return $request;
        }

        return $request->withParsedBody(null);
    }

    private function isFormRequest(ServerRequestInterface $request): bool
    {
        $mediaType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0]));

        return $mediaType === 'application/x-www-form-urlencoded' || $mediaType === 'multipart/form-data';
    }

    /**
     * Older versions of the PSR-7 bridge build the URI from the normalized query string, but the
     * query string has to be collected as it was received.
     */
    private function withRawQueryString(ServerRequestInterface $request): ServerRequestInterface
    {
        $queryString = $request->getServerParams()['QUERY_STRING'] ?? null;

        if (!is_string($queryString) || $queryString === $request->getUri()->getQuery()) {
            return $request;
        }

        return $request->withUri($request->getUri()->withQuery($queryString), true);
    }
}
