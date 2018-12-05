<?php

namespace Sentry\Laravel;

use Sentry\ClientBuilder;
use function Sentry\configureScope;
use Sentry\State\Hub;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
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
        // Publish the configuration file
        $this->publishes(array(
            __DIR__ . '/../../../config/sentry.php' => config_path(static::$abstract . '.php'),
        ), 'config');

        $this->bindEvents($this->app);

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
            'Sentry\Laravel\TestCommand',
        ));
    }

    /**
     * Bind to the Laravel event dispatcher to log events.
     *
     * @param $app
     */
    protected function bindEvents($app)
    {
        $userConfig = $app['config'][static::$abstract];

        $handler = new EventHandler($userConfig);

        $handler->subscribe($app->events);

        // In Laravel >=5.3 we can get the user context from the auth events
        if (isset($userConfig['send_default_pii']) && $userConfig['send_default_pii'] !== false && version_compare($app::VERSION, '5.3') >= 0) {
            $handler->subscribeAuthEvents($app->events);
        }
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../../../config/sentry.php', static::$abstract);

        $app = $this->app;

        $this->app->singleton(static::$abstract, function ($app) {
            $userConfig = $app['config'][static::$abstract];
            $basePath = base_path();

            // We do not want this setting to hit our main client
            unset($userConfig['breadcrumbs.sql_bindings']);
            Hub::setCurrent(new Hub((new ClientBuilder(\array_merge(
                [
                    'environment' => $app->environment(),
                    'prefixes' => array($basePath),
                    'project_root' => $basePath,
                    'excluded_app_paths' => array($basePath . '/vendor'),
                    'integrations' => [new Integration()]
                ],
                $userConfig
            )))->getClient()));

            if (isset($userConfig['send_default_pii']) && $userConfig['send_default_pii'] !== false && version_compare($app::VERSION, '5.3') < 0) {
                try {
                    // Bind user context if available
                    if ($app['auth']->check()) {
                        configureScope(function (Scope $scope) use ($app): void {
                            $scope->setUser(['id' => $app['auth']->user()->getAuthIdentifier()]);
                        });

                    }
                } catch (Exception $e) {
                    error_log(sprintf('sentry.breadcrumbs error=%s', $e->getMessage()));
                }
            }

            return Hub::getCurrent();
        });

        // Add a sentry log channel for Laravel 5.6+
        if (version_compare($app::VERSION, '5.6') >= 0) {
            $app->make('log')->extend('sentry', function ($app, array $config) {
                $channel = new LogChannel($app);
                return $channel($config);
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
