<?php

namespace Sentry\Laravel\Features;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
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
        }

        $events->listen(NotificationSent::class, [$this, 'handleNotificationSent']);
    }

    public function handleNotificationSending(NotificationSending $event): void
    {
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        if ($parentSpan === null) {
            return;
        }

        $context = (new SpanContext)
            ->setOp('notification.send')
            ->setData([
                'id' => $event->notification->id,
                'channel' => $event->channel,
                'notifiable' => $this->formatNotifiable($event->notifiable),
                'notification' => get_class($event->notification),
            ])
            ->setDescription($event->channel);

        $this->pushSpan($parentSpan->startChild($context));
    }

    public function handleNotificationSent(NotificationSent $event): void
    {
        $this->finishSpanWithStatus(SpanStatus::ok());

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

    private function finishSpanWithStatus(SpanStatus $status): void
    {
        $span = $this->maybePopSpan();

        if ($span !== null) {
            $span->setStatus($status);
            $span->finish();
        }
    }

    private function formatNotifiable(object $notifiable): string
    {
        $notifiable = get_class($notifiable);

        if ($notifiable instanceof Model) {
            $notifiable .= "({$notifiable->getKey()})";
        }

        return $notifiable;
    }
}
