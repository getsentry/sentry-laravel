<?php

namespace Sentry\Laravel\Tests;

use Sentry\Laravel\Facade;
use Sentry\Laravel\ServiceProvider;
use Sentry\State\HubInterface;

class ServiceProviderWithCustomAliasTest extends \Orchestra\Testbench\TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('custom-sentry.dsn', 'http://publickey:secretkey@sentry.dev/123');
        $app['config']->set('custom-sentry.error_types', E_ALL ^ E_DEPRECATED ^ E_USER_DEPRECATED);
    }

    protected function getPackageProviders($app)
    {
        return [
            CustomSentryServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app)
    {
        return [
            'CustomSentry' => CustomSentryFacade::class,
        ];
    }

    public function testIsBound()
    {
        $this->assertTrue(app()->bound('custom-sentry'));
        $this->assertInstanceOf(HubInterface::class, app('custom-sentry'));
        $this->assertSame(app('custom-sentry'), CustomSentryFacade::getFacadeRoot());
    }

    /**
     * @depends testIsBound
     */
    public function testEnvironment()
    {
        $this->assertEquals('testing', app('custom-sentry')->getClient()->getOptions()->getEnvironment());
    }

    /**
     * @depends testIsBound
     */
    public function testDsnWasSetFromConfig()
    {
        /** @var \Sentry\Options $options */
        $options = app('custom-sentry')->getClient()->getOptions();

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
            app('custom-sentry')->getClient()->getOptions()->getErrorTypes()
        );
    }
}

class CustomSentryServiceProvider extends ServiceProvider
{
    public static $abstract = 'custom-sentry';
}

class CustomSentryFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'custom-sentry';
    }
}
