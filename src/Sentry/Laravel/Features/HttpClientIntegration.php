<?php

namespace Sentry\Laravel\Features;

use Illuminate\Http\Client\Factory;
use Sentry\Tracing\GuzzleTracingMiddleware;

class HttpClientIntegration extends Feature
{
    public function isApplicable(): bool
    {
        return class_exists(Factory::class) && method_exists(Factory::class, 'globalMiddleware');
    }

    public function onBoot(Factory $httpClient): void
    {
        $httpClient->globalMiddleware(GuzzleTracingMiddleware::trace());
    }
}
