<?php

namespace Sentry\Laravel\Features;

use Illuminate\Console\Scheduling\Event as SchedulingEvent;
use Illuminate\Contracts\Foundation\Application;
use Sentry\CheckIn;
use Sentry\CheckInStatus;
use Sentry\Event as SentryEvent;
use Sentry\SentrySdk;

class ConsoleIntegration extends Feature
{
    /**
     * @var array<string, CheckIn> The list of checkins that are currently in progress.
     */
    private $checkInStore = [];

    public function isApplicable(): bool
    {
        return $this->container()->make(Application::class)->runningInConsole();
    }

    public function setup(): void
    {
        $startCheckIn = function (string $mutex, string $slug) {
            $this->startCheckIn($mutex, $slug);
        };
        $finishCheckIn = function (string $mutex, CheckInStatus $status) {
            $this->finishCheckIn($mutex, $status);
        };

        SchedulingEvent::macro('sentryMonitor', function (string $monitorSlug) use ($startCheckIn, $finishCheckIn) {
            /** @var SchedulingEvent $this */
            return $this
                ->before(function () use ($startCheckIn, $monitorSlug) {
                    /** @var SchedulingEvent $this */
                    $startCheckIn($this->mutexName(), $monitorSlug);
                })
                ->onSuccess(function () use ($finishCheckIn) {
                    /** @var SchedulingEvent $this */
                    $finishCheckIn($this->mutexName(), CheckInStatus::ok());
                })
                ->onFailure(function () use ($finishCheckIn) {
                    /** @var SchedulingEvent $this */
                    $finishCheckIn($this->mutexName(), CheckInStatus::error());
                });
        });
    }

    private function startCheckIn(string $mutex, string $slug): void
    {
        $options = SentrySdk::getCurrentHub()->getClient()->getOptions();

        $checkIn = new CheckIn(
            $slug,
            CheckInStatus::inProgress(),
            null,
            $options->getEnvironment(),
            $options->getRelease()
        );

        $this->checkInStore[$mutex] = $checkIn;

        $this->sendCheckIn($checkIn);
    }

    private function finishCheckIn(string $mutex, CheckInStatus $status): void
    {
        $checkIn = $this->checkInStore[$mutex] ?? null;

        // This should never happen (because we should always start before we finish), but better safe than sorry
        if ($checkIn === null) {
            return;
        }

        // We don't need to keep the checkin in memory anymore since we finished
        unset($this->checkInStore[$mutex]);

        $checkIn->setStatus($status);

        $this->sendCheckIn($checkIn);
    }

    private function sendCheckIn(CheckIn $checkIn): void
    {
        $event = SentryEvent::createCheckIn();
        $event->setCheckIn($checkIn);

        SentrySdk::getCurrentHub()->captureEvent($event);
    }
}
