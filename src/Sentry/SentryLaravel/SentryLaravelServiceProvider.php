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

    protected function registerArtisanCommands()
    {
        $this->commands([
            SentryTestCommand::class,
        ]);
    }

    protected function bindEvents($app)
    {
        $handler = new SentryLaravelEventHandler($app[static::$abstract], $app[static::$abstract . '.config']);
        $handler->subscribe($app->events);
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

            // Make sure we don't crash when we did not publish the config file
            if (is_null($user_config)) {
                $user_config = [];
            }

            return $user_config;
        });

        $this->app->singleton(static::$abstract, function ($app) {
            $user_config = $app[static::$abstract . '.config'];

            $client = SentryLaravel::getClient(array_merge(array(
                'environment' => $app->environment(),
                'prefixes' => array(base_path()),
                'app_path' => app_path(),
            ), $user_config));

            if (isset($user_config['user_context']) && $user_config['user_context'] !== false) {
                // bind user context if available
                try {
                    if ($app['auth']->check()) {
                        $user = $app['auth']->user();
                        $client->user_context(array(
                            'id' => $user->getAuthIdentifier(),
                        ));
                    }
                } catch (\Exception $e) {
                    error_log(sprintf('sentry.breadcrumbs error=%s', $e->getMessage()));
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
