<?php

namespace Sentry\Laravel;

use Sentry\State\Hub;
use Sentry\ClientBuilder;
use Sentry\State\HubInterface;
use Illuminate\Log\LogManager;
use Laravel\Lumen\Application as Lumen;
use Sentry\Integration\IntegrationInterface;
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

        if ($this->hasDsnSet()) {
            $this->bindEvents($this->app);
        }

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

        $this->configureAndRegisterClient($this->getUserConfig());

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
        $userConfig = $this->getUserConfig();

        $handler = new EventHandler($this->app->events, $userConfig);

        $handler->subscribe();

        $handler->subscribeQueueEvents($this->app->queue);

        if (isset($userConfig['send_default_pii']) && $userConfig['send_default_pii'] !== false) {
            $handler->subscribeAuthEvents();
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
            $userConfig = $this->getUserConfig();

            // We do not want this setting to hit our main client because it's Laravel specific
            unset(
                $userConfig['breadcrumbs'],
                // this is kept for backwards compatibilty and can be dropped in a breaking release
                $userConfig['breadcrumbs.sql_bindings']
            );

            $options = \array_merge(
                [
                    'environment' => $this->app->environment(),
                    'prefixes' => [$basePath],
                    'project_root' => $basePath,
                    'in_app_exclude' => [$basePath . '/vendor'],
                ],
                $userConfig,
                [
                    'integrations' => $this->getIntegrations(),
                ]
            );

            $clientBuilder = ClientBuilder::create($options);
            $clientBuilder->setSdkIdentifier(Version::SDK_IDENTIFIER);
            $clientBuilder->setSdkVersion(Version::SDK_VERSION);

            Hub::setCurrent(new Hub($clientBuilder->getClient()));

            return Hub::getCurrent();
        });

        $this->app->alias(self::$abstract, HubInterface::class);
    }

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
     * Resolve the integrations from the user configuration with the container.
     *
     * @return array
     */
    private function getIntegrations(): array
    {
        $integrations = [new Integration];

        $userIntegrations = $this->getUserConfig()['integrations'] ?? [];

        foreach ($userIntegrations as $userIntegration) {
            if ($userIntegration instanceof IntegrationInterface) {
                $integrations[] = $userIntegration;
            } elseif (\is_string($userIntegration)) {
                $integrations[] = $this->app->make($userIntegration);
            } else {
                throw new \RuntimeException('Sentry integrations should either be a container reference or a instance of `\Sentry\Integration\IntegrationInterface`.');
            }
        }

        return $integrations;
    }

    /**
     * Retrieve the user configuration.
     *
     * @return array
     */
    private function getUserConfig(): array
    {
        return $this->app['config'][static::$abstract];
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [static::$abstract];
    }
}
