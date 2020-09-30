<?php

namespace Sentry\Laravel;

use Illuminate\Support\ServiceProvider;

abstract class BaseServiceProvider extends ServiceProvider
{
    /**
     * Abstract type to bind Sentry as in the Service Container.
     *
     * @var string
     */
    public static $abstract = 'sentry';

    /**
     * Check if a DSN was set in the config.
     *
     * @return bool
     */
    protected function hasDsnSet(): bool
    {
        $config = $this->getUserConfig();

        return !empty($config['dsn']);
    }

    /**
     * Retrieve the user configuration.
     *
     * @return array
     */
    protected function getUserConfig(): array
    {
        $config = $this->app['config'][static::$abstract];

        return empty($config) ? [] : $config;
    }

    /**
     * Check if tracing was enabled in the config.
     *
     * @return bool
     */
    protected function hasTracingEnabled(): bool
    {
        $config = $this->getUserConfig();

        // We would expect a `0.0` which is considered `empty` when tracing should be disabled
        return !empty($config['traces_sample_rate']);
    }
}
