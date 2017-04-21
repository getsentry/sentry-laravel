<?php

namespace Sentry\SentryLaravel;

use Illuminate\Support\ServiceProvider;

class SentryLumenServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->app->configure('sentry');
        $this->bindEvents($this->app);
        if ($this->app->runningInConsole()) {
            $this->registerArtisanCommands();
        }
    }

    protected function registerArtisanCommands()
    {
        $this->commands([
            SentryTestCommand::class,
        ]);
    }

    protected function bindEvents($app)
    {
        $handler = new SentryLaravelEventHandler($app['sentry'], $app['sentry.config']);
        $handler->subscribe($app->events);
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('sentry.config', function ($app) {
            $user_config = $app['config']['sentry'];

            // Make sure we don't crash when we did not publish the config file
            if (is_null($user_config)) {
                $user_config = [];
            }

            return $user_config;
        });

        $this->app->singleton('sentry', function ($app) {
            $user_config = $app['sentry.config'];

            $client = SentryLaravel::getClient(array_merge(array(
                'environment' => $app->environment(),
                'prefixes' => array(base_path()),
                'app_path' => base_path() . '/app',
            ), $user_config));

            // bind user context if available
            if (isset($user_config['user_context']) && $user_config['user_context'] !== false) {
                try {
                    if ($app['auth']->check()) {
                        $user = $app['auth']->user();
                        $client->user_context(array(
                            'id' => $user->getAuthIdentifier(),
                        ));
                    }
                } catch (\Exception $e) {
                }
            }

            return $client;
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return array('sentry');
    }
}
