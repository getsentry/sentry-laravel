<?php

class SentryLaravelServiceProviderTest extends \Orchestra\Testbench\TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('sentry.dsn', 'http://public:secret@example.com/1');
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
    public function testEnvironment()
    {
        $this->assertEquals('testing', app('sentry')->environment);
    }

    /**
     * @depends testIsBound
     */
    public function testDSN()
    {
        $this->assertEquals('http://example.com/api/1/store/', app('sentry')->server);
        $this->assertEquals('public', app('sentry')->public_key);
        $this->assertEquals('secret', app('sentry')->secret_key);
        $this->assertEquals('1', app('sentry')->project);
    }

    /**
     * @depends testIsBound
     */
    public function testDidRegisterEvents()
    {
        $this->assertEquals(true, app('events')->hasListeners('router.matched') && app('events')->hasListeners('Illuminate\Routing\Events\RouteMatched'));
    }
}
