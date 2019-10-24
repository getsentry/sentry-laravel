<?php

namespace Sentry\Laravel\Tests;

use Sentry\ClientBuilderInterface;
use Sentry\Laravel\ServiceProvider;

class ServiceClientBuilderDecoratorTest extends \Orchestra\Testbench\TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('sentry.dsn', 'http://publickey:secretkey@sentry.dev/123');

        $app->extend(ClientBuilderInterface::class, function (ClientBuilderInterface $clientBuilder) {
            $clientBuilder->getOptions()->setEnvironment('from_service_container');

            return $clientBuilder;
        });
    }

    protected function getPackageProviders($app)
    {
        return [
            ServiceProvider::class,
        ];
    }

    public function testClientHasCustomSerializer()
    {
        /** @var \Sentry\Options $options */
        $options = $this->app->make('sentry')->getClient()->getOptions();

        $this->assertEquals('from_service_container', $options->getEnvironment());
    }
}
