<?php

namespace Sentry\Laravel\Tests;

use Sentry\State\Hub;
use Sentry\Laravel\Facade;
use Sentry\Laravel\ServiceProvider;

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
        $this->assertInstanceOf(Hub::class, app('sentry'));
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

        $this->assertEquals('http://sentry.dev', $options->getDsn());
        $this->assertEquals(123, $options->getProjectId());
        $this->assertEquals('publickey', $options->getPublicKey());
        $this->assertEquals('secretkey', $options->getSecretKey());
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
