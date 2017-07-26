<?php

namespace Sentry\SentryLaravel;

use Illuminate\Support\ServiceProvider;

class SentryLaravelServiceProvider extends ServiceProvider
{
    /**
     * Abstract type to bind Sentry as in the Service Container.
     *
     * @var string
     */
    public static $abstract = 'sentry';

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
            $this->package('sentry/sentry-laravel', static::$abstract);

            $app->error(function (\Exception $e) use ($app) {
                $app[static::$abstract]->captureException($e);
            });

            $app->fatal(function ($e) use ($app) {
                $app[static::$abstract]->captureException($e);
            });

            $this->bindEvents($app);
        } else {
            // the default configuration file
            $this->publishes(array(
                __DIR__ . '/config.php' => config_path(static::$abstract . '.php'),
            ), 'config');

            $this->bindEvents($app);
        }
        if ($this->app->runningInConsole()) {
            $this->registerArtisanCommands();
        }
    }

    /**
     * Register the artisan commands.
     */
    protected function registerArtisanCommands()
    {
        $this->commands(array(
            'Sentry\SentryLaravel\SentryTestCommand',
        ));
    }

    /**
     * Bind to the Laravel event dispatcher to log events.
     *
     * @param $app
     */
    protected function bindEvents($app)
    {
        $user_config = $app[static::$abstract . '.config'];

        $handler = new SentryLaravelEventHandler($app[static::$abstract], $user_config);

        $handler->subscribe($app->events);

        // In Laravel >=5.3 we can get the user context from the auth events
        if (version_compare($app::VERSION, '5.3') >= 0) {
            if (isset($user_config['user_context']) && $user_config['user_context'] !== false) {
                $handler->subscribeAuthEvents($app->events);
            }
        }
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(static::$abstract . '.config', function ($app) {
            // sentry::config is Laravel 4.x
            $user_config = $app['config'][static::$abstract] ?: $app['config'][static::$abstract . '::config'];

            // Make sure we don't crash when we did not publish the config file and the config is null
            return $user_config ?: array();
        });

        $this->app->singleton(static::$abstract, function ($app) {
            $user_config = $app[static::$abstract . '.config'];
            $base_path = base_path();
            $client = SentryLaravel::getClient(array_merge(array(
                'environment' => $app->environment(),
                'prefixes' => array($base_path),
                'app_path' => $base_path,
                'excluded_app_paths' => array($base_path . '/vendor'),
            ), $user_config));

            // In Laravel <5.3 we can get the user context from here
            if (version_compare($app::VERSION, '5.3') < 0) {
                if (isset($user_config['user_context']) && $user_config['user_context'] !== false) {
                    // Bind user context if available
                    try {
                        if ($app['auth']->check()) {
                            $client->user_context(array(
                                'id' => $app['auth']->user()->getAuthIdentifier(),
                            ));
                        }
                    } catch (\Exception $e) {
                        error_log(sprintf('sentry.breadcrumbs error=%s', $e->getMessage()));
                    }
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
        return array(static::$abstract);
    }
}
