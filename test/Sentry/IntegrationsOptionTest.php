<?php

namespace Sentry\Laravel\Tests;

use Sentry\Integration\IntegrationInterface;

class IntegrationsOptionTest extends SentryLaravelTestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app->singleton('custom-sentry-integration', static function () {
            return new IntegrationsOptionTestIntegrationStub;
        });
    }

    public function testCustomIntegrationIsResolvedFromContainerByAlias()
    {
        $this->resetApplicationWithConfig([
            'sentry.integrations' => [
                'custom-sentry-integration',
            ],
        ]);

        $this->assertNotNull($this->getHubFromContainer()->getClient()->getIntegration(IntegrationsOptionTestIntegrationStub::class));
    }

    public function testCustomIntegrationIsResolvedFromContainerByClass()
    {
        $this->resetApplicationWithConfig([
            'sentry.integrations' => [
                IntegrationsOptionTestIntegrationStub::class,
            ],
        ]);

        $this->assertNotNull($this->getHubFromContainer()->getClient()->getIntegration(IntegrationsOptionTestIntegrationStub::class));
    }

    public function testCustomIntegrationByInstance()
    {
        $this->resetApplicationWithConfig([
            'sentry.integrations' => [
                new IntegrationsOptionTestIntegrationStub,
            ],
        ]);

        $this->assertNotNull($this->getHubFromContainer()->getClient()->getIntegration(IntegrationsOptionTestIntegrationStub::class));
    }

    /**
     * Throws \ReflectionException in <=5.8 and \Illuminate\Contracts\Container\BindingResolutionException since 6.0
     * @expectedException \Exception
     */
    public function testCustomIntegrationThrowsExceptionIfNotResolvable()
    {
        $this->resetApplicationWithConfig([
            'sentry.integrations' => [
                'this-will-not-resolve',
            ],
        ]);
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testIncorrectIntegrationEntryThrowsException()
    {
        $this->resetApplicationWithConfig([
            'sentry.integrations' => [
                static function () {
                },
            ],
        ]);
    }
}

class IntegrationsOptionTestIntegrationStub implements IntegrationInterface
{
    public function setupOnce(): void
    {
    }
}
