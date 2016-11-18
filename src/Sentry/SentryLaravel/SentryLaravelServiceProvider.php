<?php

namespace Sentry\SentryLaravel;

use Log;
use Illuminate\Support\ServiceProvider;

class SentryLaravelServiceProvider extends ServiceProvider
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
        $app = $this->app;

        // Laravel 4.x compatibility
        if (version_compare($app::VERSION, '5.0') < 0) {
            $this->package('sentry/sentry-laravel', 'sentry');

            $app->error(function (\Exception $e) use ($app) {
                $app['sentry']->captureException($e);
            });

            $app->fatal(function ($e) use ($app) {
                $app['sentry']->captureException($e);
            });

            $this->bindEvents($app);
        } else {
            // the default configuration file
            $this->publishes(array(
                __DIR__ . '/config.php' => config_path('sentry.php'),
            ), 'config');

            $this->bindEvents($app);
        }
    }

    protected function bindEvents($app)
    {
        $handler = new SentryLaravelEventHandler($app['sentry'], $app['sentry.config']);
        $handler->subscribe($app->events);
        $handler->subscribeLog($app->log);
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('sentry.config', function ($app) {
            // sentry::config is Laravel 4.x
            $user_config = $app['config']['sentry'] ?: $app['config']['sentry::config'];

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
                'app_path' => app_path(),
            ), $user_config));

            // bind user context if available
            try {
                if ($app['auth']->check()) {
                    $user = $app['auth']->user();
                    $client->user_context(array(
                        'id' => $user->getAuthIdentifier(),
                    ));
                }
            } catch (\Exception $e) {
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
