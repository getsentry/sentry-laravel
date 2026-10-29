<?php

namespace Sentry\Laravel\Features;

use DateTimeZone;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as SchedulingEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Factory as Cache;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Sentry\CheckIn;
use Sentry\CheckInStatus;
use Sentry\Event as SentryEvent;
use Sentry\Laravel\Features\Concerns\TracksPushedScopesAndSpans;
use Sentry\MonitorConfig;
use Sentry\MonitorSchedule;
use Sentry\SentrySdk;
use SplObjectStorage;
use Throwable;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TransactionContext;
use Sentry\Tracing\TransactionSource;

class ConsoleSchedulingIntegration extends Feature
{
    use TracksPushedScopesAndSpans;

    /**
     * @var string|null
     */
    private $cacheStore = null;

    /**
     * @var array<string, CheckIn> The list of checkins that are currently in progress.
     */
    private $checkInStore = [];

    private $shouldHandleCheckIn = false;

    /**
     * @var SplObjectStorage<SchedulingEvent, string|null> The scheduled tasks that `sentryMonitor()` was called on, with their slug.
     */
    private $monitoredTasks;

    public function register(): void
    {
        $this->monitoredTasks = new SplObjectStorage();
        $monitoredTasks = $this->monitoredTasks;

        $startCheckIn = function (
            ?string $slug,
            SchedulingEvent $scheduled,
            ?int $checkInMargin,
            ?int $maxRuntime,
            bool $updateMonitorConfig,
            ?int $failureIssueThreshold,
            ?int $recoveryThreshold,
            ?string $schedule
        ) {
            $this->startCheckIn(
                $slug,
                $scheduled,
                $checkInMargin,
                $maxRuntime,
                $updateMonitorConfig,
                $failureIssueThreshold,
                $recoveryThreshold,
                $schedule
            );
        };
        $finishCheckIn = function (?string $slug, SchedulingEvent $scheduled, CheckInStatus $status) {
            $this->finishCheckIn($slug, $scheduled, $status);
        };

        SchedulingEvent::macro('sentryMonitor', function (
            ?string $monitorSlug = null,
            ?int $checkInMargin = null,
            ?int $maxRuntime = null,
            bool $updateMonitorConfig = true,
            ?int $failureIssueThreshold = null,
            ?int $recoveryThreshold = null,
            ?string $schedule = null
        ) use ($startCheckIn, $finishCheckIn, $monitoredTasks) {
            /** @var SchedulingEvent $this */
            if ($monitorSlug === null && empty($this->command) && empty($this->description)) {
                throw new RuntimeException('The command and description are not set, please set a slug manually for this scheduled command using the `sentryMonitor(\'your-monitor-slug\')` macro.');
            }

            $monitoredTasks->attach($this, $monitorSlug);

            return $this
                ->before(function () use (
                    $startCheckIn,
                    $monitorSlug,
                    $checkInMargin,
                    $maxRuntime,
                    $updateMonitorConfig,
                    $failureIssueThreshold,
                    $recoveryThreshold,
                    $schedule
                ) {
                    /** @var SchedulingEvent $this */
                    $startCheckIn(
                        $monitorSlug,
                        $this,
                        $checkInMargin,
                        $maxRuntime,
                        $updateMonitorConfig,
                        $failureIssueThreshold,
                        $recoveryThreshold,
                        $schedule
                    );
                })
                ->onSuccess(function () use ($finishCheckIn, $monitorSlug) {
                    /** @var SchedulingEvent $this */
                    $finishCheckIn($monitorSlug, $this, CheckInStatus::ok());
                })
                ->onFailure(function () use ($finishCheckIn, $monitorSlug) {
                    /** @var SchedulingEvent $this */
                    $finishCheckIn($monitorSlug, $this, CheckInStatus::error());
                });
        });
    }

    public function isApplicable(): bool
    {
        return true;
    }

    public function onBoot(Dispatcher $events): void
    {
        $this->shouldHandleCheckIn = true;

        $events->listen(ScheduledTaskStarting::class, [$this, 'handleScheduledTaskStarting']);
        $events->listen(ScheduledTaskFinished::class, [$this, 'handleScheduledTaskFinished']);
        $events->listen(ScheduledTaskFailed::class, [$this, 'handleScheduledTaskFailed']);

        if ($this->getUserConfig()['crons']['monitor_scheduled_tasks'] ?? false) {
            $events->listen(CommandStarting::class, [$this, 'handleCommandStarting']);
        }
    }

    /**
     * Calls `sentryMonitor()` on every scheduled task before the scheduler runs or finishes them.
     */
    public function handleCommandStarting(CommandStarting $event): void
    {
        if (!\in_array($event->command, ['schedule:run', 'schedule:finish', 'schedule:test'], true)) {
            return;
        }

        try {
            $this->monitorScheduledTasks($this->container()->make(Schedule::class));
        } catch (Throwable $exception) {
            $client = SentrySdk::getCurrentHub()->getClient();
            if ($client !== null) {
                $client->getOptions()->getLoggerOrNullLogger()->warning('Failed to set up cron monitoring for scheduled tasks.', ['exception' => $exception]);
            }
        }
    }

    public function onBootInactive(): void
    {
        $this->shouldHandleCheckIn = false;
    }

    public function useCacheStore(?string $name): void
    {
        $this->cacheStore = $name;
    }

    public function handleScheduledTaskStarting(ScheduledTaskStarting $event): void
    {
        // There is nothing for us to track if it's a background task since it will be handled by a separate process
        if (!$event->task || $event->task->runInBackground) {
            return;
        }

        // When scheduling a command class the command name will be the most descriptive
        // When a job is scheduled the command name is `null` and the job class name (or display name) is set as the description
        // When a closure is scheduled both the command name and description are `null`
        $name = $this->getCommandNameForScheduled($event->task) ?? $event->task->description ?? 'Closure';

        $context = TransactionContext::make()
            ->setName($name)
            ->setSource(TransactionSource::task())
            ->setOp('console.command.scheduled')
            ->setStartTimestamp(microtime(true));

        $transaction = SentrySdk::getCurrentHub()->startTransaction($context);

        $this->pushSpan($transaction);
    }

    public function handleScheduledTaskFinished(): void
    {
        $this->maybeFinishSpan(SpanStatus::ok());
        $this->maybePopScope();
    }

    public function handleScheduledTaskFailed(): void
    {
        $this->maybeFinishSpan(SpanStatus::internalError());
        $this->maybePopScope();
    }

    private function monitorScheduledTasks(Schedule $schedule): void
    {
        $excluded = $this->getUserConfig()['crons']['exclude_scheduled_tasks'] ?? [];
        $monitoredSlugs = [];
        $tasksBySlug = [];

        foreach ($schedule->events() as $task) {
            if ($this->monitoredTasks->contains($task)) {
                $monitoredSlugs[$this->monitoredTasks[$task] ?? $this->makeSlugForScheduled($task)] = true;

                continue;
            }

            // Closures without a name have nothing to make a stable slug from
            if (empty($task->command) && empty($task->description)) {
                continue;
            }

            $slug = $this->makeSlugForScheduled($task);

            if (!$this->isExcluded($task, $slug, $excluded)) {
                $tasksBySlug[$slug][] = $task;
            }
        }

        foreach ($tasksBySlug as $slug => $tasks) {
            // Sentry cuts slugs at 50 characters
            $taskSlug = \strlen($slug) > 50 ? substr($slug, 0, 41) . '_' . hash('crc32b', $slug) : $slug;

            // Task order isn't stable, so a shared slug can't be given to any one of the tasks
            if (\count($tasks) > 1 || isset($monitoredSlugs[$slug]) || isset($monitoredSlugs[$taskSlug])) {
                $client = SentrySdk::getCurrentHub()->getClient();
                if ($client !== null) {
                    $client->getOptions()->getLoggerOrNullLogger()->warning(sprintf('Not monitoring scheduled tasks with the slug "%s" because more than one task uses it. Give each task a unique slug with `->sentryMonitor(\'your-monitor-slug\')`.', $slug));
                }

                continue;
            }

            $crontab = $this->getMonitorCrontab($tasks[0]);

            if ($crontab === null) {
                $client = SentrySdk::getCurrentHub()->getClient();
                if ($client !== null) {
                    $client->getOptions()->getLoggerOrNullLogger()->debug(sprintf('Not monitoring the scheduled task "%s" because Sentry can\'t match its schedule. Add `->sentryMonitor()` to monitor it without a schedule.', $slug));
                }

                continue;
            }

            $tasks[0]->sentryMonitor($taskSlug, null, null, true, null, null, $crontab);
        }
    }

    private function isExcluded(SchedulingEvent $task, string $slug, array $excluded): bool
    {
        foreach ([$slug, $this->getCommandNameForScheduled($task), $task->description] as $name) {
            if ($name !== null && $name !== '' && Str::is($excluded, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns null when Sentry would expect runs that the task skips or reads the crontab differently.
     */
    private function getMonitorCrontab(SchedulingEvent $task): ?string
    {
        $hasConditions = (function (): bool {
            // `withoutOverlapping()` adds a reject, but an overlap means the previous run is late anyway
            return \count($this->filters) > 0 || \count($this->rejects) > ($this->withoutOverlapping ? 1 : 0);
        })->call($task);

        // Without a timezone the check-in is read as UTC, and Sentry needs a timezone name, not an offset
        $timezone = $task->timezone instanceof DateTimeZone ? $task->timezone : new DateTimeZone($task->timezone ?? 'UTC');
        $isTimezoneSupported = $timezone->getLocation() !== false && ($task->timezone !== null || date_default_timezone_get() === 'UTC');

        // Sub-minute tasks (`everyTenSeconds()`) can't be expressed as a crontab
        if ($hasConditions || ($task->repeatSeconds ?? null) !== null || !$isTimezoneSupported) {
            return null;
        }

        // cron-expression reads "?" as "*"
        $fields = preg_split('/\s+/', trim(str_replace('?', '*', $task->getExpression())));

        if ($fields === false || \count($fields) !== 5) {
            return null;
        }

        // cron-expression 2.x (Laravel < 8) needs both day fields to match, 3.x either, and cronsim depends on a leading "*"
        if ($fields[2] !== '*' && $fields[4] !== '*') {
            return null;
        }

        // `lastDayOfMonth()` sets this month's last day, so its crontab changes each month. `->cron('0 0 L * *')` stays stable.
        if ($fields[2] === (string) Carbon::now()->endOfMonth()->day && $fields[3] === '*') {
            return null;
        }

        foreach ($fields as $position => $field) {
            foreach (explode(',', $field) as $item) {
                // cron-expression 2.x reads "5/15" differently, 3.x rejects it
                $isSupported = preg_match('/^(?:(?:\*|\d+-\d+)(?:\/\d+)?|\d+|[a-z]{3}(?:-[a-z]{3})?)$/i', $item)
                    || ($position === 2 && $item === 'L')
                    || ($position === 4 && preg_match('/^(?:\d(?:L|#[1-5])|[a-z]{3}#[1-5])$/i', $item));

                if (!$isSupported) {
                    return null;
                }
            }
        }

        return implode(' ', $fields);
    }

    private function startCheckIn(
        ?string $slug,
        SchedulingEvent $scheduled,
        ?int $checkInMargin,
        ?int $maxRuntime,
        bool $updateMonitorConfig,
        ?int $failureIssueThreshold,
        ?int $recoveryThreshold,
        ?string $schedule
    ): void {
        if (!$this->shouldHandleCheckIn) {
            return;
        }

        $checkInSlug = $slug ?? $this->makeSlugForScheduled($scheduled);

        $checkIn = $this->createCheckIn($checkInSlug, CheckInStatus::inProgress());

        if ($updateMonitorConfig || $slug === null) {
            $timezone = $scheduled->timezone;

            if ($timezone instanceof DateTimeZone) {
                $timezone = $timezone->getName();
            }

            $checkIn->setMonitorConfig(new MonitorConfig(
                MonitorSchedule::crontab($schedule ?? $scheduled->getExpression()),
                $checkInMargin,
                $maxRuntime,
                $timezone,
                $failureIssueThreshold,
                $recoveryThreshold
            ));
        }

        $cacheKey = $this->buildCacheKey($scheduled->mutexName(), $checkInSlug);

        $this->checkInStore[$cacheKey] = $checkIn;

        if ($scheduled->runInBackground) {
            $this->storeCheckInIdInCache($cacheKey, $checkIn->getId(), $scheduled->expiresAt * 60);
        }

        $this->sendCheckIn($checkIn);
    }

    private function finishCheckIn(?string $slug, SchedulingEvent $scheduled, CheckInStatus $status): void
    {
        if (!$this->shouldHandleCheckIn) {
            return;
        }

        $checkInSlug = $slug ?? $this->makeSlugForScheduled($scheduled);

        $cacheKey = $this->buildCacheKey($scheduled->mutexName(), $checkInSlug);

        $checkIn = $this->checkInStore[$cacheKey] ?? null;

        if ($checkIn === null && $scheduled->runInBackground) {
            $checkInId = $this->getCheckInIdFromCache($cacheKey);

            if ($checkInId !== null) {
                $checkIn = $this->createCheckIn($checkInSlug, $status, $checkInId);
            }
        }

        // This should never happen (because we should always start before we finish), but better safe than sorry
        if ($checkIn === null) {
            return;
        }

        // We don't need to keep the checkIn ID stored since we finished executing the command
        unset($this->checkInStore[$cacheKey]);

        if ($scheduled->runInBackground) {
            $this->forgetCheckInIdFromCache($cacheKey);
        }

        $checkIn->setStatus($status);

        $this->sendCheckIn($checkIn);
    }

    private function sendCheckIn(CheckIn $checkIn): void
    {
        $event = SentryEvent::createCheckIn();
        $event->setCheckIn($checkIn);

        SentrySdk::getCurrentHub()->captureEvent($event);
    }

    private function createCheckIn(string $slug, CheckInStatus $status, ?string $id = null): CheckIn
    {
        $options = SentrySdk::getCurrentHub()->getClient()->getOptions();

        return new CheckIn(
            Str::limit($slug, 128, ''),
            $status,
            $id,
            $options->getRelease(),
            $options->getEnvironment()
        );
    }

    private function buildCacheKey(string $mutex, string $slug): string
    {
        // We use the mutex name as part of the cache key to avoid collisions between the same commands with the same schedule but with different slugs
        return 'sentry:checkIn:' . sha1("{$mutex}:{$slug}");
    }

    private function makeSlugForScheduled(SchedulingEvent $scheduled): string
    {
        if (empty($scheduled->command)) {
            if (!empty($scheduled->description) && class_exists($scheduled->description)) {
                $generatedSlug = Str::slug(
                    // We reverse the class name to have the class name at the start of the slug instead of at the end (and possibly cut off)
                    implode('_', array_reverse(explode('\\', $scheduled->description)))
                );
            } else {
                $generatedSlug = Str::slug($scheduled->description);
            }
        } else {
            $generatedSlug = Str::slug(
                str_replace(
                    // `:` is commonly used in the command name, so we replace it with `-` to avoid it being stripped out by the slug function
                    ':',
                    '-',
                    trim(
                        // The command string always starts with the PHP binary, so we remove it since it's not relevant to the slug
                        Str::after($scheduled->command, ConsoleApplication::phpBinary())
                    )
                )
            );
        }

        return "scheduled_{$generatedSlug}";
    }

    private function getCommandNameForScheduled(SchedulingEvent $scheduled): ?string
    {
        if (!$scheduled->command) {
            return null;
        }

        // The command string always starts with the PHP binary and artisan binary, so we remove it since it's not relevant to the name
        return trim(
            Str::after($scheduled->command, ConsoleApplication::phpBinary() . ' ' . ConsoleApplication::artisanBinary())
        );
    }

    /** @return CacheRepository|ContextRepository */
    private function resolveCache()
    {
        // Context is available since Laravel 11 but the hidden context is only
        // passed to child processes since Laravel 12.40.2 which is required
        // for this feature to work correctly for background scheduled tasks.
        if (class_exists(ContextRepository::class) && version_compare(app()->version(), '12.40.2', '>=')) {
            return $this->container()->make(ContextRepository::class);
        }

        return $this->container()->make(Cache::class)->store($this->cacheStore);
    }

    private function getCheckInIdFromCache(string $cacheKey): ?string
    {
        $cache = $this->resolveCache();

        if ($cache instanceof CacheRepository) {
            return $cache->get($cacheKey);
        }

        return $cache->getHidden($cacheKey);
    }

    private function storeCheckInIdInCache(string $cacheKey, string $checkInId, int $expiration): void
    {
        $cache = $this->resolveCache();

        if ($cache instanceof CacheRepository) {
            $cache->put($cacheKey, $checkInId, $expiration);
        } else {
            $cache->addHidden($cacheKey, $checkInId);
        }
    }

    private function forgetCheckInIdFromCache(string $cacheKey): void
    {
        $cache = $this->resolveCache();

        if ($cache instanceof CacheRepository) {
            $cache->forget($cacheKey);
        } else {
            $cache->forgetHidden($cacheKey);
        }
    }
}
