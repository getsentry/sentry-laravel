<?php

namespace Sentry\Laravel\Tests;

use Sentry\Laravel\Facade;
use Sentry\Laravel\ServiceProvider;
use Sentry\State\HubInterface;

class ServiceProviderTest extends \Orchestra\Testbench\TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('sentry.dsn', 'http://publickey:secretkey@sentry.dev/123');
        $app['config']->set('sentry.error_types', E_ALL ^ E_DEPRECATED ^ E_USER_DEPRECATED);
    }

    protected function getPackageProviders($app)
    {
        return [
            ServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app)
    {
        return [
            'Sentry' => Facade::class,
        ];
    }

    public function testIsBound()
    {
        $this->assertTrue(app()->bound('sentry'));
        $this->assertInstanceOf(HubInterface::class, app('sentry'));
        $this->assertSame(app('sentry'), Facade::getFacadeRoot());
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
    public function testDsnWasSetFromConfig()
    {
        /** @var \Sentry\Options $options */
        $options = app('sentry')->getClient()->getOptions();

        $this->assertEquals('http://sentry.dev', $options->getDsn()->getScheme() . '://' . $options->getDsn()->getHost());
        $this->assertEquals(123, $options->getDsn()->getProjectId());
        $this->assertEquals('publickey', $options->getDsn()->getPublicKey());
        $this->assertEquals('secretkey', $options->getDsn()->getSecretKey());
    }

    /**
     * @depends testIsBound
     */
    public function testErrorTypesWasSetFromConfig()
    {
        $this->assertEquals(
            E_ALL ^ E_DEPRECATED ^ E_USER_DEPRECATED,
            app('sentry')->getClient()->getOptions()->getErrorTypes()
        );
    }
}
