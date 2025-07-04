<?php

namespace Sentry\Laravel\Tests\Features;

use DateTimeZone;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use RuntimeException;
use Sentry\Laravel\Tests\TestCase;
use Illuminate\Console\Scheduling\Event;

class ConsoleSchedulingIntegrationTest extends TestCase
{
    public function testScheduleMacro(): void
    {
        /** @var Event $scheduledEvent */
        $scheduledEvent = $this->getScheduler()
            ->call(function () {})
            ->sentryMonitor('test-monitor');

        $scheduledEvent->run($this->app);

        // We expect a total of 2 events to be sent to Sentry:
        // 1. The start check-in event
        // 2. The finish check-in event
        $this->assertSentryCheckInCount(2);

        $finishCheckInEvent = $this->getLastSentryEvent();

        $this->assertNotNull($finishCheckInEvent->getCheckIn());
        $this->assertEquals('test-monitor', $finishCheckInEvent->getCheckIn()->getMonitorSlug());
    }

    /**
     * When a timezone was defined on a command this would fail with:
     * Sentry\MonitorConfig::__construct(): Argument #4 ($timezone) must be of type ?string, DateTimeZone given
     * This test ensures that the timezone is properly converted to a string as expected.
     */
    public function testScheduleMacroWithTimeZone(): void
    {
        $expectedTimezone = 'UTC';

        /** @var Event $scheduledEvent */
        $scheduledEvent = $this->getScheduler()
            ->call(function () {})
            ->timezone(new DateTimeZone($expectedTimezone))
            ->sentryMonitor('test-timezone-monitor');

        $scheduledEvent->run($this->app);

        // We expect a total of 2 events to be sent to Sentry:
        // 1. The start check-in event
        // 2. The finish check-in event
        $this->assertSentryCheckInCount(2);

        $finishCheckInEvent = $this->getLastSentryEvent();

        $this->assertNotNull($finishCheckInEvent->getCheckIn());
        $this->assertEquals($expectedTimezone, $finishCheckInEvent->getCheckIn()->getMonitorConfig()->getTimezone());
    }

    public function testScheduleMacroAutomaticSlugForCommand(): void
    {
        /** @var Event $scheduledEvent */
        $scheduledEvent = $this->getScheduler()->command('inspire')->sentryMonitor();

        $scheduledEvent->run($this->app);

        // We expect a total of 2 events to be sent to Sentry:
        // 1. The start check-in event
        // 2. The finish check-in event
        $this->assertSentryCheckInCount(2);

        $finishCheckInEvent = $this->getLastSentryEvent();

        $this->assertNotNull($finishCheckInEvent->getCheckIn());
        $this->assertEquals('scheduled_artisan-inspire', $finishCheckInEvent->getCheckIn()->getMonitorSlug());
    }

    public function testScheduleMacroAutomaticSlugForJob(): void
    {
        /** @var Event $scheduledEvent */
        $scheduledEvent = $this->getScheduler()->job(ScheduledQueuedJob::class)->sentryMonitor();

        $scheduledEvent->run($this->app);

        // We expect a total of 2 events to be sent to Sentry:
        // 1. The start check-in event
        // 2. The finish check-in event
        $this->assertSentryCheckInCount(2);

        $finishCheckInEvent = $this->getLastSentryEvent();

        $this->assertNotNull($finishCheckInEvent->getCheckIn());
        // Scheduled is duplicated here because of the class name of the queued job, this is not a bug just unfortunate naming for the test class
        $this->assertEquals('scheduled_scheduledqueuedjob-features-tests-laravel-sentry', $finishCheckInEvent->getCheckIn()->getMonitorSlug());
    }

    public function testScheduleMacroWithoutSlugCommandOrDescriptionOrName(): void
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

        $this->assertSentryCheckInCount(0);
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

    /** @define-env envSamplingAllTransactions */
    public function testScheduledClosureCreatesTransaction(): void
    {
        $this->getScheduler()->call(function () {})->everyMinute();

        $this->artisan('schedule:run');

        $this->assertSentryTransactionCount(1);

        $transaction = $this->getLastSentryEvent();

        $this->assertEquals('Closure', $transaction->getTransaction());
    }

    /** @define-env envSamplingAllTransactions */
    public function testScheduledJobCreatesTransaction(): void
    {
        $this->getScheduler()->job(ScheduledQueuedJob::class)->everyMinute();

        $this->artisan('schedule:run');

        $this->assertSentryTransactionCount(1);

        $transaction = $this->getLastSentryEvent();

        $this->assertEquals(ScheduledQueuedJob::class, $transaction->getTransaction());
    }

    private function getScheduler(): Schedule
    {
        return $this->app->make(Schedule::class);
    }
}

class ScheduledQueuedJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
    }
}
