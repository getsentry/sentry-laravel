<?php

namespace Sentry\Laravel;

use Sentry\State\Hub;
use Sentry\ClientBuilder;
use Illuminate\Support\ServiceProvider;

abstract class BaseServiceProvider extends ServiceProvider
{
    /**
     * Configure and register a Sentry client with the hub.
     *
     * @param array $userConfig
     */
    protected function configureAndRegisterClient(array $userConfig): void
    {
        $basePath = base_path();

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
     * Test if the Laravel application is version $minimum or greater.
     *
     * @param string $minumum
     *
     * @return bool
     */
    protected function isMinimumLaravelVersion(string $minumum): bool
    {
        $app = $this->app;

        return version_compare($app::VERSION, $minumum) >= 0;
    }
}
