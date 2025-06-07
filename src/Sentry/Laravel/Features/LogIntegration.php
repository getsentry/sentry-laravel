<?php

namespace Sentry\Laravel\Features;

use Illuminate\Support\Facades\Log;
use Sentry\Laravel\LogChannel;
use Sentry\Laravel\Logs\LogChannel as LogsLogChannel;

class LogIntegration extends Feature
{
    public function isApplicable(): bool
    {
        return true;
    }

    public function register(): void
    {
        Log::extend('sentry', function ($app, array $config) {
            return (new LogChannel($app))($config);
        });

        Log::extend('sentry_logs', function ($app, array $config) {
            return (new LogsLogChannel($app))($config);
        });
    }
}
