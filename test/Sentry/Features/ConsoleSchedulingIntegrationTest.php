<?php

namespace Sentry\Laravel\Tests\Features;

use DateTimeZone;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Application;
use Illuminate\Log\Context\Repository as ContextRepository;
use ReflectionClass;
use RuntimeException;
use Sentry\CheckInStatus;
use Sentry\Laravel\Features\ConsoleSchedulingIntegration;
use Sentry\Laravel\Tests\TestCase;

class ConsoleSchedulingIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (\PHP_VERSION_ID >= 70300 && \PHP_VERSION_ID < 70400 && version_compare(Application::VERSION, '8', '>=') && version_compare(Application::VERSION, '9', '<')) {
            $this->markTestSkipped('These tests don\'t run on Laravel 8 with PHP 7.3.');
        }

        parent::setUp();
    }

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

    public function testBackgroundScheduledTaskUsesContextForCheckInId(): void
    {
        if (!class_exists(ContextRepository::class)) {
            $this->markTestSkipped('Laravel Context is not available in this Laravel version.');
        }

        /** @var Event $scheduledEvent */
        $scheduledEvent = $this->getScheduler()
            ->command('inspire')
            ->runInBackground()
            ->sentryMonitor('test-background-monitor');

        // Get the integration instance
        $integration = $this->app->make(ConsoleSchedulingIntegration::class);

        // Use reflection to access private properties
        $integrationReflection = new ReflectionClass($integration);
        $checkInStoreProperty = $integrationReflection->getProperty('checkInStore');

        // Use reflection to access protected callback arrays on the Event
        $eventReflection = new ReflectionClass($scheduledEvent);
        $beforeCallbacksProperty = $eventReflection->getProperty('beforeCallbacks');
        $afterCallbacksProperty = $eventReflection->getProperty('afterCallbacks');

        // Run the before callbacks (this triggers startCheckIn)
        foreach ($beforeCallbacksProperty->getValue($scheduledEvent) as $callback) {
            $this->app->call($callback->bindTo($scheduledEvent));
        }

        // We should have 1 check-in event (the start)
        $this->assertSentryCheckInCount(1);

        $startCheckInEvent = $this->getLastSentryEvent();
        $startCheckInId = $startCheckInEvent->getCheckIn()->getId();

        // Clear the in-memory store to simulate being in a different process
        // This is what happens when a background task runs in a separate process
        $checkInStoreProperty->setValue($integration, []);

        // Set exitCode to 0 to simulate successful execution
        // (onSuccess callbacks check exitCode === 0)
        $scheduledEvent->exitCode = 0;

        // Run the success callbacks (this triggers finishCheckIn)
        // In a real background task, this would run in a separate process
        // but it should be able to retrieve the check-in ID from Context
        foreach ($afterCallbacksProperty->getValue($scheduledEvent) as $callback) {
            $this->app->call($callback->bindTo($scheduledEvent));
        }

        // We should now have 2 check-in events (start + finish)
        $this->assertSentryCheckInCount(2);

        $finishCheckInEvent = $this->getLastSentryEvent();
        $finishCheckInId = $finishCheckInEvent->getCheckIn()->getId();

        // The finish check-in should have the same ID as the start check-in
        // This verifies that the ID was correctly retrieved from Context
        $this->assertEquals($startCheckInId, $finishCheckInId);
        $this->assertEquals(CheckInStatus::ok(), $finishCheckInEvent->getCheckIn()->getStatus());
    }

    public function testBackgroundScheduledTaskOverlappingExecutionsHaveDistinctCheckInIds(): void
    {
        if (!class_exists(ContextRepository::class)) {
            $this->markTestSkipped('Laravel Context is not available in this Laravel version.');
        }

        /** @var Event $scheduledEvent */
        $scheduledEvent = $this->getScheduler()
            ->command('inspire')
            ->runInBackground()
            ->sentryMonitor('test-overlapping-monitor');

        // Get the integration and context instances
        $integration = $this->app->make(ConsoleSchedulingIntegration::class);
        $context = $this->app->make(ContextRepository::class);

        // Use reflection to access private properties on integration
        $integrationReflection = new ReflectionClass($integration);
        $checkInStoreProperty = $integrationReflection->getProperty('checkInStore');

        // Use reflection to access protected callback arrays on the Event
        $eventReflection = new ReflectionClass($scheduledEvent);
        $beforeCallbacksProperty = $eventReflection->getProperty('beforeCallbacks');
        $afterCallbacksProperty = $eventReflection->getProperty('afterCallbacks');

        // Simulate Task A starting
        foreach ($beforeCallbacksProperty->getValue($scheduledEvent) as $callback) {
            $this->app->call($callback->bindTo($scheduledEvent));
        }

        $this->assertSentryCheckInCount(1);
        $taskAStartEvent = $this->getLastSentryEvent();
        $taskACheckInId = $taskAStartEvent->getCheckIn()->getId();

        // Capture Task A's context (simulates the context being passed to the spawned process)
        $taskAContext = $context->allHidden();

        // Clear in-memory store (simulates scheduler continuing after spawning Task A)
        $checkInStoreProperty->setValue($integration, []);

        // Simulate Task B starting (overlapping execution)
        foreach ($beforeCallbacksProperty->getValue($scheduledEvent) as $callback) {
            $this->app->call($callback->bindTo($scheduledEvent));
        }

        $this->assertSentryCheckInCount(2);
        $taskBStartEvent = $this->getLastSentryEvent();
        $taskBCheckInId = $taskBStartEvent->getCheckIn()->getId();

        // Task A and Task B should have different check-in IDs
        $this->assertNotEquals($taskACheckInId, $taskBCheckInId);

        // Capture Task B's context
        $taskBContext = $context->allHidden();

        // Clear in-memory store (prepare for finish simulations)
        $checkInStoreProperty->setValue($integration, []);

        // Set exitCode to 0 to simulate successful execution
        $scheduledEvent->exitCode = 0;

        // Simulate Task A finishing (restore Task A's context)
        $context->flush();
        $context->addHidden($taskAContext);
        foreach ($afterCallbacksProperty->getValue($scheduledEvent) as $callback) {
            $this->app->call($callback->bindTo($scheduledEvent));
        }

        $this->assertSentryCheckInCount(3);
        $taskAFinishEvent = $this->getLastSentryEvent();

        // Task A's finish should use Task A's check-in ID
        $this->assertEquals($taskACheckInId, $taskAFinishEvent->getCheckIn()->getId());

        // Clear in-memory store again
        $checkInStoreProperty->setValue($integration, []);

        // Simulate Task B finishing (restore Task B's context)
        $context->flush();
        $context->addHidden($taskBContext);
        foreach ($afterCallbacksProperty->getValue($scheduledEvent) as $callback) {
            $this->app->call($callback->bindTo($scheduledEvent));
        }

        $this->assertSentryCheckInCount(4);
        $taskBFinishEvent = $this->getLastSentryEvent();

        // Task B's finish should use Task B's check-in ID
        $this->assertEquals($taskBCheckInId, $taskBFinishEvent->getCheckIn()->getId());
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
