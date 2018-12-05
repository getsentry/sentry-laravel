<?php

namespace Sentry\Laravel;

use Sentry\ClientBuilder;
use function Sentry\configureScope;
use Sentry\State\Hub;
use Sentry\State\Scope;

class LumenServiceProvider extends \Illuminate\Support\ServiceProvider
{
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

    protected function bindEvents($app)
    {
        $handler = new EventHandler($app['sentry.config']);
        $handler->subscribe($app->events);
    }

    protected function registerArtisanCommands()
    {
        $this->commands(array(
            'Sentry\Laravel\TestCommand',
        ));
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('sentry.config', function ($app) {
            $userConfig = $app['config']['sentry'];

            // Make sure we don't crash when we did not publish the config file
            if (is_null($userConfig)) {
                $userConfig = array();
            }

            return $userConfig;
        });

        $this->app->singleton('sentry', function ($app) {
            $userConfig = $app['sentry.config'];
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
                        $user = $app['auth']->user();
                        configureScope(function (Scope $scope) use ($app, $user): void {
                            $scope->setUser(['id' => $user->getAuthIdentifier()]);
                        });

                    }
                } catch (\Exception $e) {
                    error_log(sprintf('sentry.breadcrumbs error=%s', $e->getMessage()));
                }
            }

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
        return array('sentry');
    }
}
