<?php

namespace Sentry\Laravel;

use Sentry\State\Hub;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Abstract type to bind Sentry as in the Service Container.
     *
     * @var string
     */
    public static $abstract = 'sentry';

    /**
     * Bootstrap the application events.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../../config/sentry.php' => config_path(static::$abstract . '.php'),
        ], 'config');

        $this->configureAndRegisterClient($this->app['config'][static::$abstract]);

        $this->bindEvents($this->app);

        if ($this->app->runningInConsole()) {
            $this->registerArtisanCommands();
        }
    }

    /**
     * Bind to the Laravel event dispatcher to log events.
     *
     * @param \Illuminate\Contracts\Foundation\Application|\Illuminate\Foundation\Application $app
     */
    protected function bindEvents($app): void
    {
        $userConfig = $app['config'][static::$abstract];

        $handler = new EventHandler($userConfig);

        $handler->subscribe($app->events);

        // In Laravel >=5.3 we can get the user context from the auth events
        if (isset($userConfig['send_default_pii']) && $userConfig['send_default_pii'] !== false && $this->isMinimumLaravelVersion('5.3')) {
            $handler->subscribeAuthEvents($app->events);
        }
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../../config/sentry.php', static::$abstract);

        $this->app->singleton(static::$abstract, function () {
            return Hub::getCurrent();
        });

        // Add a sentry log channel for Laravel 5.6+
        if ($this->isMinimumLaravelVersion('5.6')) {
            $this->app->make('log')->extend('sentry', function ($app, array $config) {
                return (new LogChannel($app))($config);
            });
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return array(static::$abstract);
    }
}
