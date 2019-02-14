<?php

namespace Sentry\Laravel;

use Sentry\State\Hub;

class LumenServiceProvider extends BaseServiceProvider
{
    /**
     * Bootstrap the application events.
     */
    public function boot(): void
    {
        $this->app->configure('sentry');

        $this->configureAndRegisterClient($this->app['sentry.config']);

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
        (new EventHandler($app['sentry.config']))->subscribe($app->events);
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton('sentry.config', function ($app) {
            $userConfig = $app['config']['sentry'];

            // Make sure we don't crash when we did not publish the config file
            if (is_null($userConfig)) {
                $userConfig = [];
            }

            return $userConfig;
        });

        $this->app->singleton('sentry', function () {
            return Hub::getCurrent();
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return ['sentry'];
    }
}
