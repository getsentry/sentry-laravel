<?php

namespace Sentry\Laravel;

use Sentry\State\Hub;
use Sentry\ClientBuilder;
use Illuminate\Log\LogManager;
use Laravel\Lumen\Application as Lumen;
use Illuminate\Foundation\Application as Laravel;
use Illuminate\Support\ServiceProvider as IlluminateServiceProvider;

class ServiceProvider extends IlluminateServiceProvider
{
    /**
     * Abstract type to bind Sentry as in the Service Container.
     *
     * @var string
     */
    public static $abstract = 'sentry';

    /**
     * Boot the service provider.
     */
    public function boot(): void
    {
        $this->app->make(self::$abstract);

        $this->bindEvents($this->app);

        if ($this->app->runningInConsole()) {
            if ($this->app instanceof Laravel) {
                $this->publishes([
                    __DIR__ . '/../../../config/sentry.php' => config_path(static::$abstract . '.php'),
                ], 'config');
            }

            $this->registerArtisanCommands();
        }
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        if ($this->app instanceof Lumen) {
            $this->app->configure('sentry');
        }

        $this->mergeConfigFrom(__DIR__ . '/../../../config/sentry.php', static::$abstract);

        $this->configureAndRegisterClient($this->app['config'][static::$abstract]);

        if (($logManager = $this->app->make('log')) instanceof LogManager) {
            $logManager->extend('sentry', function ($app, array $config) {
                return (new LogChannel($app))($config);
            });
        }
    }

    /**
     * Bind to the Laravel event dispatcher to log events.
     */
    protected function bindEvents(): void
    {
        $userConfig = $this->app['config'][static::$abstract];

        $handler = new EventHandler($userConfig);

        $handler->subscribe($this->app->events);

        if (isset($userConfig['send_default_pii']) && $userConfig['send_default_pii'] !== false) {
            $handler->subscribeAuthEvents($this->app->events);
        }
    }

    /**
     * Register the artisan commands.
     */
    protected function registerArtisanCommands(): void
    {
        $this->commands([
            TestCommand::class,
        ]);
    }

    /**
     * Configure and register the Sentry client with the container.
     */
    protected function configureAndRegisterClient(): void
    {
        $this->app->singleton(static::$abstract, function () {
            $basePath = base_path();
            $userConfig = $this->app['config'][static::$abstract];

            // We do not want this setting to hit our main client because it's Laravel specific
            unset($userConfig['breadcrumbs.sql_bindings']);

            $options = \array_merge(
                [
                    'environment' => $this->app->environment(),
                    'prefixes' => [$basePath],
                    'project_root' => $basePath,
                    'in_app_exclude' => [$basePath . '/vendor'],
                    'integrations' => [new Integration],
                ],
                $userConfig
            );

            $clientBuilder = ClientBuilder::create($options);
            $clientBuilder->setSdkIdentifier(Version::SDK_IDENTIFIER);
            $clientBuilder->setSdkVersion(Version::SDK_VERSION);

            Hub::setCurrent(new Hub($clientBuilder->getClient()));

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
        return array(static::$abstract);
    }
}
