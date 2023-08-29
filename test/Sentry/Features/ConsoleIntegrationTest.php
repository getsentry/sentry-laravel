<?php

namespace Sentry\Features;

use Illuminate\Console\Scheduling\Schedule;
use RuntimeException;
use Sentry\Laravel\Tests\TestCase;
use Illuminate\Console\Scheduling\Event;

class ConsoleIntegrationTest extends TestCase
{
    public function testScheduleMacro(): void
    {
        /** @var Event $scheduledEvent */
        $scheduledEvent = $this->getScheduler()->call(function () {})->sentryMonitor('test-monitor');

        $scheduledEvent->run($this->app);

        // We expect a total of 2 events to be sent to Sentry:
        // 1. The start check-in event
        // 2. The finish check-in event
        $this->assertEquals(2, $this->getEventsCount());

        $finishCheckInEvent = $this->getLastEvent();

        $this->assertNotNull($finishCheckInEvent->getCheckIn());
        $this->assertEquals('test-monitor', $finishCheckInEvent->getCheckIn()->getMonitorSlug());
    }

    public function testScheduleMacroAutomaticSlug(): void
    {
        /** @var Event $scheduledEvent */
        $scheduledEvent = $this->getScheduler()->command('inspire')->sentryMonitor();

        $scheduledEvent->run($this->app);

        // We expect a total of 2 events to be sent to Sentry:
        // 1. The start check-in event
        // 2. The finish check-in event
        $this->assertEquals(2, $this->getEventsCount());

        $finishCheckInEvent = $this->getLastEvent();

        $this->assertNotNull($finishCheckInEvent->getCheckIn());
        $this->assertEquals('scheduled_artisan-inspire', $finishCheckInEvent->getCheckIn()->getMonitorSlug());
    }

    public function testScheduleMacroWithoutSlugOrCommandName(): void
    {
        $this->expectException(RuntimeException::class);

        $this->getScheduler()->call(function () {})->sentryMonitor();
    }

    /** @define-env envWithoutDsnSet */
    public function testScheduleMacroWithoutDsnSet(): void
    {
        /** @var Event $scheduledEvent */
        $scheduledEvent = $this->getScheduler()->call(function () {})->sentryMonitor('test-monitor');

        $scheduledEvent->run($this->app);

        $this->assertEquals(0, $this->getEventsCount());
    }

    public function testScheduleMacroIsRegistered(): void
    {
        if (!method_exists(Event::class, 'flushMacros')) {
            $this->markTestSkipped('Macroable::flushMacros() is not available in this Laravel version.');
        }

        Event::flushMacros();

        $this->refreshApplication();

        $this->assertTrue(Event::hasMacro('sentryMonitor'));
    }

    /** @define-env envWithoutDsnSet */
    public function testScheduleMacroIsRegisteredWithoutDsnSet(): void
    {
        if (!method_exists(Event::class, 'flushMacros')) {
            $this->markTestSkipped('Macroable::flushMacros() is not available in this Laravel version.');
        }

        Event::flushMacros();

        $this->refreshApplication();

        $this->assertTrue(Event::hasMacro('sentryMonitor'));
    }

    private function getScheduler(): Schedule
    {
        return $this->app->make(Schedule::class);
    }
}
