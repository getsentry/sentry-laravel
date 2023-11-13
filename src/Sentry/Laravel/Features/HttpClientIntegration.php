<?php

namespace Sentry\Laravel\Features;

use Illuminate\Http\Client\Factory;
use Sentry\Tracing\GuzzleTracingMiddleware;

class HttpClientIntegration extends Feature
{
    public function isApplicable(): bool
    {
        // The `globalMiddleware` method was added in Laravel 10.14
        return class_exists(Factory::class) && method_exists(Factory::class, 'globalMiddleware');
    }

    public function onBoot(Factory $httpClient): void
    {
        $httpClient->globalMiddleware(GuzzleTracingMiddleware::trace());
    }
}
