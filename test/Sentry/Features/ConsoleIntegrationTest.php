<?php

namespace Sentry\Features;

use Sentry\Laravel\Tests\TestCase;
use Illuminate\Console\Scheduling\Event;

class ConsoleIntegrationTest extends TestCase
{
    public function testArtisanCommandIsRegistered(): void
    {
        Event::flushMacros();

        $this->refreshApplication();

        $this->assertTrue(Event::hasMacro('sentryMonitor'));
    }

    public function testArtisanCommandIsRegisteredWithoutDsnSet(): void
    {
        Event::flushMacros();

        $this->resetApplicationWithConfig([
            'sentry.dsn' => null,
        ]);

        $this->assertTrue(Event::hasMacro('sentryMonitor'));
    }
}
