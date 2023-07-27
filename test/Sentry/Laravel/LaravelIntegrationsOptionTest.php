<?php

namespace Sentry\Laravel\Tests\Laravel;

use Illuminate\Contracts\Container\BindingResolutionException;
use RuntimeException;
use Sentry\Integration\ErrorListenerIntegration;
use Sentry\Integration\ExceptionListenerIntegration;
use Sentry\Integration\FatalErrorListenerIntegration;
use Sentry\Integration\IntegrationInterface;
use Sentry\Laravel\Tests\TestCase;

class LaravelIntegrationsOptionTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app->singleton('custom-sentry-integration', static function () {
            return new IntegrationsOptionTestIntegrationStub;
        });
    }

    public function testCustomIntegrationIsResolvedFromContainerByAlias(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.integrations' => [
                'custom-sentry-integration',
            ],
        ]);

        $this->assertNotNull($this->getClientFromContainer()->getIntegration(IntegrationsOptionTestIntegrationStub::class));
    }

    public function testCustomIntegrationIsResolvedFromContainerByClass(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.integrations' => [
                IntegrationsOptionTestIntegrationStub::class,
            ],
        ]);

        $this->assertNotNull($this->getClientFromContainer()->getIntegration(IntegrationsOptionTestIntegrationStub::class));
    }

    public function testCustomIntegrationByInstance(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.integrations' => [
                new IntegrationsOptionTestIntegrationStub,
            ],
        ]);

        $this->assertNotNull($this->getClientFromContainer()->getIntegration(IntegrationsOptionTestIntegrationStub::class));
    }

    public function testCustomIntegrationThrowsExceptionIfNotResolvable(): void
    {
        $this->expectException(BindingResolutionException::class);

        $this->resetApplicationWithConfig([
            'sentry.integrations' => [
                'this-will-not-resolve',
            ],
        ]);
    }

    public function testIncorrectIntegrationEntryThrowsException(): void
    {
        $this->expectException(RuntimeException::class);

        $this->resetApplicationWithConfig([
            'sentry.integrations' => [
                static function () {
                },
            ],
        ]);
    }

    public function testDisabledIntegrationsAreNotPresent(): void
    {
        $client = $this->getClientFromContainer();

        $this->assertNull($client->getIntegration(ErrorListenerIntegration::class));
        $this->assertNull($client->getIntegration(ExceptionListenerIntegration::class));
        $this->assertNull($client->getIntegration(FatalErrorListenerIntegration::class));
    }

    public function testDisabledIntegrationsAreNotPresentWithCustomIntegrations(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.integrations' => [
                new IntegrationsOptionTestIntegrationStub,
            ],
        ]);

        $client = $this->getClientFromContainer();

        $this->assertNotNull($client->getIntegration(IntegrationsOptionTestIntegrationStub::class));

        $this->assertNull($client->getIntegration(ErrorListenerIntegration::class));
        $this->assertNull($client->getIntegration(ExceptionListenerIntegration::class));
        $this->assertNull($client->getIntegration(FatalErrorListenerIntegration::class));
    }
}

class IntegrationsOptionTestIntegrationStub implements IntegrationInterface
{
    public function setupOnce(): void
    {
    }
}
