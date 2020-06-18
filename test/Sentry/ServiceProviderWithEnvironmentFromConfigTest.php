<?php

namespace Sentry;

use Sentry\Laravel\Tests\SentryLaravelTestCase;

class ServiceProviderWithEnvironmentFromConfigTest extends SentryLaravelTestCase
{
    public function testSentryEnvironmentDefaultsToLaravelEnvironment()
    {
        $this->assertEquals('testing', app()->environment());
    }

    public function testEmptySentryEnvironmentDefaultsToLaravelEnvironment()
    {
        $this->resetApplicationWithConfig([
            'sentry.environment' => '',
        ]);

        $this->assertEquals('testing', $this->getHubFromContainer()->getClient()->getOptions()->getEnvironment());

        $this->resetApplicationWithConfig([
            'sentry.environment' => null,
        ]);

        $this->assertEquals('testing', $this->getHubFromContainer()->getClient()->getOptions()->getEnvironment());
    }

    public function testSentryEnvironmentDefaultGetsOverriddenByConfig()
    {
        $this->resetApplicationWithConfig([
            'sentry.environment' => 'not_testing',
        ]);

        $this->assertEquals('not_testing', $this->getHubFromContainer()->getClient()->getOptions()->getEnvironment());
    }
}
