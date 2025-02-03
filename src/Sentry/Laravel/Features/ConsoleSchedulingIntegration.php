<?php

namespace Sentry\Laravel\Features;

use DateTimeZone;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as SchedulingEvent;
use Illuminate\Contracts\Cache\Factory as Cache;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use RuntimeException;
use Sentry\CheckIn;
use Sentry\CheckInStatus;
use Sentry\Event as SentryEvent;
use Sentry\Laravel\Features\Concerns\TracksPushedScopesAndSpans;
use Sentry\MonitorConfig;
use Sentry\MonitorSchedule;
use Sentry\SentrySdk;
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

    public function register(): void
    {
        $startCheckIn = function (
            ?string $slug,
            SchedulingEvent $scheduled,
            ?int $checkInMargin,
            ?int $maxRuntime,
            bool $updateMonitorConfig,
            ?int $failureIssueThreshold,
            ?int $recoveryThreshold
        ) {
            $this->startCheckIn(
                $slug,
                $scheduled,
                $checkInMargin,
                $maxRuntime,
                $updateMonitorConfig,
                $failureIssueThreshold,
                $recoveryThreshold
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
            ?int $recoveryThreshold = null
        ) use ($startCheckIn, $finishCheckIn) {
            /** @var SchedulingEvent $this */
            if ($monitorSlug === null && empty($this->command) && empty($this->description)) {
                throw new RuntimeException('The command and description are not set, please set a slug manually for this scheduled command using the `sentryMonitor(\'your-monitor-slug\')` macro.');
            }

            return $this
                ->before(function () use (
                    $startCheckIn,
                    $monitorSlug,
                    $checkInMargin,
                    $maxRuntime,
                    $updateMonitorConfig,
                    $failureIssueThreshold,
                    $recoveryThreshold
                ) {
                    /** @var SchedulingEvent $this */
                    $startCheckIn(
                        $monitorSlug,
                        $this,
                        $checkInMargin,
                        $maxRuntime,
                        $updateMonitorConfig,
                        $failureIssueThreshold,
                        $recoveryThreshold
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

    private function startCheckIn(
        ?string $slug,
        SchedulingEvent $scheduled,
        ?int $checkInMargin,
        ?int $maxRuntime,
        bool $updateMonitorConfig,
        ?int $failureIssueThreshold,
        ?int $recoveryThreshold
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
                MonitorSchedule::crontab($scheduled->getExpression()),
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
            $this->resolveCache()->put($cacheKey, $checkIn->getId(), $scheduled->expiresAt * 60);
        }

        $this->sendCheckIn($checkIn);
    }

    private function finishCheckIn(?string $slug, SchedulingEvent $scheduled, CheckInStatus $status): void
    {
        if (!$this->shouldHandleCheckIn) {
            return;
        }

        $mutex = $scheduled->mutexName();

        $checkInSlug = $slug ?? $this->makeSlugForScheduled($scheduled);

        $cacheKey = $this->buildCacheKey($mutex, $checkInSlug);

        $checkIn = $this->checkInStore[$cacheKey] ?? null;

        if ($checkIn === null && $scheduled->runInBackground) {
            $checkInId = $this->resolveCache()->get($cacheKey);

            if ($checkInId !== null) {
                $checkIn = $this->createCheckIn($checkInSlug, $status, $checkInId);
            }
        }

        // This should never happen (because we should always start before we finish), but better safe than sorry
        if ($checkIn === null) {
            return;
        }

        // We don't need to keep the checkIn ID stored since we finished executing the command
        unset($this->checkInStore[$mutex]);

        if ($scheduled->runInBackground) {
            $this->resolveCache()->forget($cacheKey);
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

    private function resolveCache(): Repository
    {
        return $this->container()->make(Cache::class)->store($this->cacheStore);
    }
}
