<?php

namespace Sentry\Laravel\Features;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Sentry\Breadcrumb;
use Sentry\Laravel\Features\Concerns\TracksPushedScopesAndSpans;
use Sentry\Laravel\Integration;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;

class NotificationsIntegration extends Feature
{
    use TracksPushedScopesAndSpans;

    private const FEATURE_KEY = 'notifications';

    public function isApplicable(): bool
    {
        return $this->isTracingFeatureEnabled(self::FEATURE_KEY)
            || $this->isBreadcrumbFeatureEnabled(self::FEATURE_KEY);
    }

    public function onBoot(Dispatcher $events): void
    {
        if ($this->isTracingFeatureEnabled(self::FEATURE_KEY)) {
            $events->listen(NotificationSending::class, [$this, 'handleNotificationSending']);
            $events->listen(NotificationFailed::class, [$this, 'handleNotificationFailed']);
        }

        $events->listen(NotificationSent::class, [$this, 'handleNotificationSent']);
    }

    public function handleNotificationSending(NotificationSending $event): void
    {
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        // If there is no sampled span there is no need to handle the event
        if ($parentSpan === null || !$parentSpan->getSampled()) {
            return;
        }

        $context = SpanContext::make()
            ->setOp('notification.send')
            ->setData([
                'id' => $event->notification->id,
                'channel' => $event->channel,
                'notifiable' => $this->formatNotifiable($event->notifiable),
                'notification' => get_class($event->notification),
            ])
            ->setOrigin('auto.laravel.notifications')
            ->setDescription($event->channel);

        $this->pushSpan($parentSpan->startChild($context));
    }

    public function handleNotificationSent(NotificationSent $event): void
    {
        $this->maybeFinishNotificationSpan($event, SpanStatus::ok());

        if ($this->isBreadcrumbFeatureEnabled(self::FEATURE_KEY)) {
            Integration::addBreadcrumb(new Breadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_DEFAULT,
                'notification.sent',
                'Sent notification',
                [
                    'channel' => $event->channel,
                    'notifiable' => $this->formatNotifiable($event->notifiable),
                    'notification' => get_class($event->notification),
                ]
            ));
        }
    }

    public function handleNotificationFailed(NotificationFailed $event): void
    {
        $this->maybeFinishNotificationSpan($event, SpanStatus::internalError());
    }

    /**
     * A channel can dispatch `NotificationFailed` and still return normally (followed by `NotificationSent`),
     * or dispatch it more than once, so only finish the span if it belongs to this notification and channel.
     *
     * @param NotificationSent|NotificationFailed $event
     */
    private function maybeFinishNotificationSpan($event, SpanStatus $status): void
    {
        $span = end($this->currentSpanStack);

        if (!$span
            || $span->getData()['id'] !== $event->notification->id
            || $span->getData()['channel'] !== $event->channel
        ) {
            return;
        }

        $this->maybeFinishSpan($status);
    }

    /**
     * @param mixed $notifiable
     */
    private function formatNotifiable($notifiable): string
    {
        if (is_string($notifiable) || is_numeric($notifiable)) {
            return (string)$notifiable;
        }

        if (is_object($notifiable)) {
            $result = get_class($notifiable);

            if ($notifiable instanceof Model) {
                $result .= "({$notifiable->getKey()})";
            }

            return $result;
        }

        return 'unknown';
    }
}
