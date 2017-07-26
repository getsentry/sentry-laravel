<?php

use Sentry\SentryLaravel;


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
            'Sentry' => 'Sentry\SentryLaravel\SentryFacade'
        ];
    }

    public function testIsBound()
    {
        $this->assertEquals(app()->bound('sentry'), true);
    }

    /**
     * @depends testIsBound
     */
    public function testEnvironment()
    {
        $this->assertEquals(app('sentry')->environment, 'testing');
    }

    /**
     * @depends testIsBound
     */
    public function testDSN()
    {
        $this->assertEquals(app('sentry')->server, 'http://example.com/api/1/store/');
        $this->assertEquals(app('sentry')->public_key, 'public');
        $this->assertEquals(app('sentry')->secret_key, 'secret');
        $this->assertEquals(app('sentry')->project, '1');
    }
}
