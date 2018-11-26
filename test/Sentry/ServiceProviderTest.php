<?php

class ServiceProviderTest extends \Orchestra\Testbench\TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('sentry.dsn', 'http://public:secret@example.com/1');
    }

    protected function getPackageProviders($app)
    {
        return ['Sentry\Laravel\ServiceProvider'];
    }

    protected function getPackageAliases($app)
    {
        return [
            'Sentry' => 'Sentry\Laravel\Facade',
        ];
    }

    public function testIsBound()
    {
        $this->assertTrue(app()->bound('sentry'));
    }

    /**
     * @depends testIsBound
     */
    public function testEnvironment()
    {
        $this->assertEquals('testing', app('sentry')->getClient()->getOptions()->getEnvironment());
    }

    /**
     * @depends testIsBound
     */
    public function testDSN()
    {
        $this->assertNotNull(app('sentry')->getClient()->getOptions()->getDsn());
    }
}
