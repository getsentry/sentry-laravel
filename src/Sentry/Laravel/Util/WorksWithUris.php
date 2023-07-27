<?php

namespace Sentry\Laravel\Util;

use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\UriInterface;

trait WorksWithUris
{
    /**
     * Construct a full URI.
     */
    protected function getFullUri(string $url): UriInterface
    {
        return new Uri($url);
    }

    /**
     * Construct a partial URI, excluding the authority, query and fragment parts.
     */
    protected function getPartialUri(UriInterface $uri): string
    {
        return (string) Uri::fromParts([
            'scheme' => $uri->getScheme(),
            'host' => $uri->getHost(),
            'port' => $uri->getPort(),
            'path' => $uri->getPath(),
        ]);
    }
}
