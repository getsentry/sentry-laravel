<?php

namespace Sentry\SentryLaravel;

use Exception;
use Illuminate\Support\ServiceProvider;

class SentryLumenServiceProvider extends ServiceProvider
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
        $this->app->configure('sentry');
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('sentry', function ($app) {
            $user_config = $app['config']['sentry'];

            // Make sure we don't crash when we did not publish the config file
            if (is_null($user_config)) {
                $user_config = [];
            }

            $client = SentryLaravel::getClient(array_merge([
                'environment' => $app->environment(),
                'prefixes'    => [base_path()],
                'app_path'    => base_path() . '/app',
            ], $user_config));

            // bind user context if available
            try {
                if ($app['auth']->check()) {
                    $user = $app['auth']->user();
                    $client->user_context([
                        'id' => $user->getAuthIdentifier(),
                    ]);
                }
            } catch (Exception $e) {
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
        return ['sentry'];
    }
}
