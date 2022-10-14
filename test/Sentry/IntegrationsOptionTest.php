<?php

namespace Sentry\Laravel\Tests;

use Exception;
use RuntimeException;
use Sentry\Integration\IntegrationInterface;
use Sentry\Integration\ErrorListenerIntegration;
use Sentry\Integration\ExceptionListenerIntegration;
use Sentry\Integration\FatalErrorListenerIntegration;

class IntegrationsOptionTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
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
     */
    public function testCustomIntegrationThrowsExceptionIfNotResolvable()
    {
        $this->expectException(Exception::class);

        $this->resetApplicationWithConfig([
            'sentry.integrations' => [
                'this-will-not-resolve',
            ],
        ]);
    }

    public function testIncorrectIntegrationEntryThrowsException()
    {
        $this->expectException(RuntimeException::class);

        $this->resetApplicationWithConfig([
            'sentry.integrations' => [
                static function () {
                },
            ],
        ]);
    }

    public function testDisabledIntegrationsAreNotPresent()
    {
        $integrations = $this->getHubFromContainer()->getClient()->getOptions()->getIntegrations();

        foreach ($integrations as $integration) {
            $this->ensureIsNotDisabledIntegration($integration);
        }

        $this->assertTrue(true, 'Not all disabled integrations are actually disabled.');
    }

    public function testDisabledIntegrationsAreNotPresentWithCustomIntegrations()
    {
        $this->resetApplicationWithConfig([
            'sentry.integrations' => [
                new IntegrationsOptionTestIntegrationStub,
            ],
        ]);

        $this->assertNotNull($this->getHubFromContainer()->getClient()->getIntegration(IntegrationsOptionTestIntegrationStub::class));
        $this->assertNull($this->getHubFromContainer()->getClient()->getIntegration(ErrorListenerIntegration::class));
        $this->assertNull($this->getHubFromContainer()->getClient()->getIntegration(ExceptionListenerIntegration::class));
        $this->assertNull($this->getHubFromContainer()->getClient()->getIntegration(FatalErrorListenerIntegration::class));
    }
}

class IntegrationsOptionTestIntegrationStub implements IntegrationInterface
{
    public function setupOnce(): void
    {
    }
}
