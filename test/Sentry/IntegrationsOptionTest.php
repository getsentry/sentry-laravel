<?php

namespace Sentry\Laravel\Tests;

use Sentry\Integration\IntegrationInterface;
use Sentry\Integration\ErrorListenerIntegration;
use Sentry\Integration\ExceptionListenerIntegration;
use Sentry\Integration\FatalErrorListenerIntegration;

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
     *
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

        $integrations = $this->getHubFromContainer()->getClient()->getOptions()->getIntegrations();

        $found = false;

        foreach ($integrations as $integration) {
            $this->ensureIsNotDisabledIntegration($integration);

            if ($integration instanceof IntegrationsOptionTestIntegrationStub) {
                $found = true;
            }
        }

        $this->assertTrue($found, 'No IntegrationsOptionTestIntegrationStub found in final integrations enabled');
    }

    /**
     * Make sure the passed integration is not one of the disabled integrations.
     *
     * @param \Sentry\Integration\IntegrationInterface $integration
     */
    private function ensureIsNotDisabledIntegration(IntegrationInterface $integration)
    {
        if ($integration instanceof ErrorListenerIntegration) {
            $this->fail('Should not have ErrorListenerIntegration registered');
        }

        if ($integration instanceof ExceptionListenerIntegration) {
            $this->fail('Should not have ExceptionListenerIntegration registered');
        }

        if ($integration instanceof FatalErrorListenerIntegration) {
            $this->fail('Should not have FatalErrorListenerIntegration registered');
        }
    }
}

class IntegrationsOptionTestIntegrationStub implements IntegrationInterface
{
    public function setupOnce(): void
    {
    }
}
