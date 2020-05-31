<?php

namespace Sentry\Laravel;

use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\ClientBuilder;
use Sentry\State\HubInterface;
use Illuminate\Log\LogManager;
use Sentry\ClientBuilderInterface;
use Laravel\Lumen\Application as Lumen;
use Sentry\Integration as SdkIntegration;
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
        $this->app->make(static::$abstract);

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

        if ($this->app->bound('queue')) {
            $handler->subscribeQueueEvents($this->app->queue);
        }

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
        $this->app->bind(ClientBuilderInterface::class, function () {
            $basePath = base_path();
            $userConfig = $this->getUserConfig();

            unset(
                // We do not want this setting to hit our main client because it's Laravel specific
                $userConfig['breadcrumbs'],
                // We resolve the integrations through the container later, so we initially do not pass it to the SDK yet
                $userConfig['integrations'],
                // This is kept for backwards compatibility and can be dropped in a future breaking release
                $userConfig['breadcrumbs.sql_bindings']
            );

            $options = \array_merge(
                [
                    'environment' => $this->app->environment(),
                    'prefixes' => [$basePath],
                    'in_app_exclude' => ["{$basePath}/vendor"],
                ],
                $userConfig
            );

            $clientBuilder = ClientBuilder::create($options);

            // Set the Laravel SDK identifier and version
            $clientBuilder->setSdkIdentifier(Version::SDK_IDENTIFIER);
            $clientBuilder->setSdkVersion(Version::SDK_VERSION);

            return $clientBuilder;
        });

        $this->app->singleton(static::$abstract, function () {
            /** @var \Sentry\ClientBuilderInterface $clientBuilder */
            $clientBuilder = $this->app->make(ClientBuilderInterface::class);

            $options = $clientBuilder->getOptions();

            $userIntegrations = $this->resolveIntegrationsFromUserConfig();

            $options->setIntegrations(static function (array $integrations) use ($options, $userIntegrations) {
                $allIntegrations = array_merge($integrations, $userIntegrations);

                if (!$options->hasDefaultIntegrations()) {
                    return $allIntegrations;
                }

                // Remove the default error and fatal exception listeners to let Laravel handle those
                // itself. These event are still bubbling up through the documented changes in the users
                // `ExceptionHandler` of their application or through the log channel integration to Sentry
                return array_filter($allIntegrations, static function (SdkIntegration\IntegrationInterface $integration): bool {
                    if ($integration instanceof SdkIntegration\ErrorListenerIntegration) {
                        return false;
                    }

                    if ($integration instanceof SdkIntegration\ExceptionListenerIntegration) {
                        return false;
                    }

                    if ($integration instanceof SdkIntegration\FatalErrorListenerIntegration) {
                        return false;
                    }

                    return true;
                });
            });

            $hub = new Hub($clientBuilder->getClient());

            SentrySdk::setCurrentHub($hub);

            return $hub;
        });

        $this->app->alias(static::$abstract, HubInterface::class);
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
    private function resolveIntegrationsFromUserConfig(): array
    {
        $integrations = [new Integration];

        $userIntegrations = $this->getUserConfig()['integrations'] ?? [];

        foreach ($userIntegrations as $userIntegration) {
            if ($userIntegration instanceof SdkIntegration\IntegrationInterface) {
                $integrations[] = $userIntegration;
            } elseif (\is_string($userIntegration)) {
                $resolvedIntegration = $this->app->make($userIntegration);

                if (!($resolvedIntegration instanceof SdkIntegration\IntegrationInterface)) {
                    throw new \RuntimeException('Sentry integrations should a instance of `\Sentry\Integration\IntegrationInterface`.');
                }

                $integrations[] = $resolvedIntegration;
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
        $config = $this->app['config'][static::$abstract];

        return empty($config) ? [] : $config;
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
