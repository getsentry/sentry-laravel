<?php

use Sentry\SentryLaravel;

class SentryLaravelServiceProviderWithoutDSNTest extends \Orchestra\Testbench\TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('sentry.dsn', null);
    }

    protected function getPackageProviders($app)
    {
        return ['Sentry\SentryLaravel\SentryLaravelServiceProvider'];
    }

    protected function getPackageAliases($app)
    {
        return [
            'Sentry' => 'Sentry\SentryLaravel\SentryFacade',
        ];
    }

    public function testIsBound()
    {
        $this->assertEquals(true, app()->bound('sentry'));
    }

    /**
     * @depends testIsBound
     */
    public function testDidNotRegisterEvents()
    {
        $this->assertEquals(false, app('events')->hasListeners('router.matched') && app('events')->hasListeners('Illuminate\Routing\Events\RouteMatched'));
    }
}
