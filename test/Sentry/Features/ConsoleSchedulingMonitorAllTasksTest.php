<?php

namespace Sentry\Laravel\Tests\Features;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Sentry\CheckIn;
use Sentry\Laravel\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class ConsoleSchedulingMonitorAllTasksTest extends TestCase
{
    protected $setupConfig = [
        'sentry.crons.monitor_scheduled_tasks' => true,
        'sentry.crons.exclude_scheduled_tasks' => ['excluded:*', 'scheduled_*-skipped'],
    ];

    protected function setUp(): void
    {
        if (\PHP_VERSION_ID >= 70300 && \PHP_VERSION_ID < 70400 && version_compare(Application::VERSION, '8', '>=') && version_compare(Application::VERSION, '9', '<')) {
            $this->markTestSkipped('These tests don\'t run on Laravel 8 with PHP 7.3.');
        }

        parent::setUp();
    }

    public function testScheduledTasksAreMonitoredWhenTheSchedulerRuns(): void
    {
        $this->getScheduler()->call(function () {})->name('send-report')->hourly();
        $this->getScheduler()->call(function () {})->name('every-minute')->everyMinute();

        // Laravel only dispatches `CommandStarting` outside of unit tests
        $this->startCommand('schedule:run');
        $this->artisan('schedule:run');

        $this->assertSentryCheckInCount(2);

        $checkIn = $this->getLastCheckIn();

        $this->assertEquals('scheduled_every-minute', $checkIn->getMonitorSlug());
        $this->assertEquals('* * * * *', $checkIn->getMonitorConfig()->getSchedule()->getValue());
    }

    public function testScheduledTasksAreNotMonitoredByOtherCommands(): void
    {
        $task = $this->getScheduler()->call(function () {})->name('send-report');

        $this->startCommand('schedule:list');
        $task->run($this->app);

        $this->assertSentryCheckInCount(0);
    }

    public function testManuallyMonitoredTaskIsNotMonitoredTwice(): void
    {
        $task = $this->getScheduler()->call(function () {})->name('send-report')->sentryMonitor('custom-slug');

        $this->startCommand('schedule:run');
        $this->startCommand('schedule:run');
        $task->run($this->app);

        $this->assertSentryCheckInCount(2);
        $this->assertEquals('custom-slug', $this->getLastCheckIn()->getMonitorSlug());
    }

    public function testUnnamedClosureIsNotMonitored(): void
    {
        $task = $this->getScheduler()->call(function () {});

        $this->startCommand('schedule:run');
        $task->run($this->app);

        $this->assertSentryCheckInCount(0);
    }

    public function testExcludedTasksAreNotMonitored(): void
    {
        $tasks = [
            $this->getScheduler()->call(function () {})->name('excluded:by-name'),
            $this->getScheduler()->call(function () {})->name('report-skipped'),
            $this->getScheduler()->call(function () {})->name('report-included'),
        ];

        $this->startCommand('schedule:run');

        foreach ($tasks as $task) {
            $task->run($this->app);
        }

        $this->assertSentryCheckInCount(2);
        $this->assertEquals('scheduled_report-included', $this->getLastCheckIn()->getMonitorSlug());
    }

    public function testTasksWithTheSameNameAndDifferentSchedulesGetTheirOwnMonitor(): void
    {
        $hourly = $this->getScheduler()->call(function () {})->name('send-report')->hourly();
        $daily = $this->getScheduler()->call(function () {})->name('send-report')->daily();

        $this->startCommand('schedule:run');
        $hourly->run($this->app);
        $hourlySlug = $this->getLastCheckIn()->getMonitorSlug();
        $daily->run($this->app);
        $dailySlug = $this->getLastCheckIn()->getMonitorSlug();

        $this->assertStringStartsWith('scheduled_send-report_', $hourlySlug);
        $this->assertStringStartsWith('scheduled_send-report_', $dailySlug);
        $this->assertNotEquals($hourlySlug, $dailySlug);
    }

    public function testLongSlugKeepsTheScheduleHash(): void
    {
        $name = str_repeat('send-report-', 10);
        $hourly = $this->getScheduler()->call(function () {})->name($name)->hourly();
        $this->getScheduler()->call(function () {})->name($name)->daily();

        $this->startCommand('schedule:run');
        $hourly->run($this->app);

        $slug = $this->getLastCheckIn()->getMonitorSlug();

        $this->assertSame(50, \strlen($slug));
        $this->assertSame(1, preg_match('/_[0-9a-f]{8}$/', $slug));
    }

    public function testTaskWithTheSameNameAsAManuallyMonitoredTaskGetsItsOwnMonitor(): void
    {
        $this->getScheduler()->call(function () {})->name('send-report')->daily()->sentryMonitor();
        $hourly = $this->getScheduler()->call(function () {})->name('send-report')->hourly();

        $this->startCommand('schedule:run');
        $hourly->run($this->app);

        $this->assertStringStartsWith('scheduled_send-report_', $this->getLastCheckIn()->getMonitorSlug());
    }

    public function testTasksWithTheSameScheduleInDifferentTimezonesGetTheirOwnMonitor(): void
    {
        $vienna = $this->getScheduler()->call(function () {})->name('send-report')->daily()->timezone('Europe/Vienna');
        $tokyo = $this->getScheduler()->call(function () {})->name('send-report')->daily()->timezone('Asia/Tokyo');

        $this->startCommand('schedule:run');
        $vienna->run($this->app);
        $viennaSlug = $this->getLastCheckIn()->getMonitorSlug();
        $tokyo->run($this->app);

        $this->assertNotEquals($viennaSlug, $this->getLastCheckIn()->getMonitorSlug());
    }

    /**
     * @dataProvider monitorConfigDataProvider
     */
    public function testMonitorConfig(callable $configure, ?string $expectedCrontab): void
    {
        $task = $configure($this->getScheduler()->call(function () {})->name('send-report')->timezone('UTC'));

        $this->startCommand('schedule:run');
        $task->run($this->app);

        $monitorConfig = $this->getLastCheckIn()->getMonitorConfig();

        $this->assertEquals('scheduled_send-report', $this->getLastCheckIn()->getMonitorSlug());
        $this->assertEquals($expectedCrontab, $monitorConfig === null ? null : $monitorConfig->getSchedule()->getValue());
    }

    public static function monitorConfigDataProvider(): iterable
    {
        yield 'crontab' => [static function (Event $task) { return $task->weekdays()->at('9:30'); }, '30 9 * * 1-5'];
        yield 'without overlapping' => [static function (Event $task) { return $task->hourly()->preventOverlapsUsing(new InMemoryEventMutex())->withoutOverlapping(); }, '0 * * * *'];
        yield 'last day of month' => [static function (Event $task) { return $task->cron('0 0 L * *'); }, '0 0 L * *'];
        yield 'question mark' => [static function (Event $task) { return $task->cron('0 0 ? * MON'); }, '0 0 * * MON'];
        yield 'named timezone' => [static function (Event $task) { return $task->daily()->timezone('Europe/Vienna'); }, '0 0 * * *'];
        yield 'nearest weekday' => [static function (Event $task) { return $task->cron('0 0 15W * *'); }, null];
        yield 'day step and weekday' => [static function (Event $task) { return $task->cron('0 0 */2 * 1'); }, null];
        yield 'day and weekday' => [static function (Event $task) { return $task->cron('0 0 1 * 1'); }, null];
        yield 'step from a number' => [static function (Event $task) { return $task->cron('5/15 * * * *'); }, null];
        yield 'step over a range' => [static function (Event $task) { return $task->cron('0 9-17/2 * * *'); }, '0 9-17/2 * * *'];
        yield 'when' => [static function (Event $task) { return $task->daily()->when(function () { return true; }); }, null];
        yield 'skip' => [static function (Event $task) { return $task->daily()->skip(function () { return false; }); }, null];
        yield 'between' => [static function (Event $task) { return $task->hourly()->between('8:00', '17:00'); }, null];
        yield 'offset timezone' => [static function (Event $task) { return $task->daily()->timezone('+05:00'); }, null];
    }

    public function testSubMinuteTaskHasNoMonitorConfig(): void
    {
        if (!method_exists(Event::class, 'everyTenSeconds')) {
            $this->markTestSkipped('Sub-minute tasks require Laravel 10.15+.');
        }

        $task = $this->getScheduler()->call(function () {})->name('send-report')->everyTenSeconds();

        $this->startCommand('schedule:run');
        $task->run($this->app);

        $this->assertNull($this->getLastCheckIn()->getMonitorConfig());
    }

    public function testScheduledTasksAreNotMonitoredByDefault(): void
    {
        $this->resetApplicationWithConfig([]);

        $this->getScheduler()->call(function () {})->name('every-minute')->everyMinute();

        $this->startCommand('schedule:run');
        $this->artisan('schedule:run');

        $this->assertSentryCheckInCount(0);
    }

    private function startCommand(string $command): void
    {
        $this->app['events']->dispatch(new CommandStarting($command, new ArrayInput([]), new NullOutput()));
    }

    private function getLastCheckIn(): CheckIn
    {
        $checkIn = $this->getLastSentryEvent()->getCheckIn();

        $this->assertNotNull($checkIn);

        return $checkIn;
    }

    private function getScheduler(): Schedule
    {
        return $this->app->make(Schedule::class);
    }
}

// Laravel < 10 removes the cache mutex of callback tasks at shutdown, after the test app is gone
class InMemoryEventMutex implements EventMutex
{
    public function create(Event $event)
    {
        return true;
    }

    public function exists(Event $event)
    {
        return false;
    }

    public function forget(Event $event)
    {
    }
}
