<?php

namespace Sentry\Laravel;

use Sentry\ClientBuilder;
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
        $user_config = $app['config'][static::$abstract];

        $handler = new EventHandler($user_config);

        $handler->subscribe($app->events);

        // In Laravel >=5.3 we can get the user context from the auth events
        if (isset($user_config['send_default_pii']) && $user_config['send_default_pii'] !== false && version_compare($app::VERSION, '5.3') >= 0) {
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
            $user_config = $app['config'][static::$abstract];
            $base_path = base_path();

            // This is our init
            $options = new Options(array_merge(array(
                'environment' => $app->environment(),
                'prefixes' => array($base_path),
                'project_root' => $base_path,
                'excluded_app_paths' => array($base_path . '/vendor'),
            ), $user_config));
            Hub::setCurrent(new Hub(ClientBuilder::createClient(Client::class, $options)->getClient()));

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
