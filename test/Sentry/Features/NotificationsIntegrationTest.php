<?php

namespace Sentry\Laravel\Tests\Features;

use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\NotificationSender;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Sentry\Laravel\Tests\TestCase;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;

class NotificationsIntegrationTest extends TestCase
{
    protected $defaultSetupConfig = [
        'sentry.tracing.views' => false,
    ];

    public function testSpanIsRecorded(): void
    {
        $span = $this->sendNotificationAndRetrieveSpan();

        $this->assertEquals('mail', $span->getDescription());
        $this->assertEquals('mail', $span->getData()['channel']);
        $this->assertEquals('notification.send', $span->getOp());
        $this->assertEquals(SpanStatus::ok(), $span->getStatus());
    }

    public function testSpanIsNotRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.notifications.enabled' => false,
        ]);

        $this->sendNotificationAndExpectNoSpan();
    }

    public function testSpanIsFinishedWithErrorWhenChannelReportsFailureAndThrows(): void
    {
        $this->assertFailedSpanIsFinished(NotificationsIntegrationTestReportingAndThrowingChannel::class);
    }

    public function testSpanKeepsErrorStatusWhenChannelReportsFailureAndReturns(): void
    {
        $this->assertFailedSpanIsFinished(NotificationsIntegrationTestReportingChannel::class);
    }

    public function testSpanIsFinishedWithErrorWhenChannelThrows(): void
    {
        if (!property_exists(NotificationSender::class, 'failedEventWasDispatched')) {
            $this->markTestSkipped('This Laravel version does not dispatch NotificationFailed when a channel throws.');
        }

        $this->assertFailedSpanIsFinished(NotificationsIntegrationTestThrowingChannel::class);
    }

    public function testNestedFailureDoesNotFinishOuterSpan(): void
    {
        $transaction = $this->startTransaction();

        Notification::route(NotificationsIntegrationTestNestingChannel::class, 'sentry')
            ->notifyNow(new NotificationsIntegrationTestChannelNotification(NotificationsIntegrationTestNestingChannel::class));

        [, $outer, $inner] = $transaction->getSpanRecorder()->getSpans();

        $this->assertSame($outer, NotificationsIntegrationTestNestingChannel::$spanAfterNestedSend);
        $this->assertEquals(SpanStatus::internalError(), $inner->getStatus());
        $this->assertEquals(SpanStatus::ok(), $outer->getStatus());
        $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan());
    }

    private function assertFailedSpanIsFinished(string $channel): void
    {
        $transaction = $this->startTransaction();

        try {
            Notification::route($channel, 'sentry')->notifyNow(new NotificationsIntegrationTestChannelNotification($channel));
        } catch (\RuntimeException $e) {
            $this->assertSame('Delivery failed', $e->getMessage());
        }

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertNotNull($spans[1]->getEndTimestamp());
        $this->assertEquals(SpanStatus::internalError(), $spans[1]->getStatus());
        $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan());
    }

    public function testBreadcrumbIsRecorded(): void
    {
        $this->sendTestNotification();

        $this->assertCount(1, $this->getCurrentSentryBreadcrumbs());

        $breadcrumb = $this->getLastSentryBreadcrumb();

        $this->assertEquals('notification.sent', $breadcrumb->getCategory());
    }

    public function testBreadcrumbIsNotRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.notifications.enabled' => false,
        ]);

        $this->sendTestNotification();

        $this->assertCount(0, $this->getCurrentSentryBreadcrumbs());
    }

    private function sendTestNotification(): void
    {
        // We fake the mail so that no actual email is sent but the notification is still sent with all it's events
        Mail::fake();

        Notification::route('mail', 'sentry@example.com')->notifyNow(new NotificationsIntegrationTestNotification);
    }

    private function sendNotificationAndRetrieveSpan(): Span
    {
        $transaction = $this->startTransaction();

        $this->sendTestNotification();

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);

        return $spans[1];
    }

    private function sendNotificationAndExpectNoSpan(): void
    {
        $transaction = $this->startTransaction();

        $this->sendTestNotification();

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(1, $spans);
    }
}

class NotificationsIntegrationTestNotification extends \Illuminate\Notifications\Notification
{
    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return new \Illuminate\Notifications\Messages\MailMessage;
    }
}

class NotificationsIntegrationTestChannelNotification extends \Illuminate\Notifications\Notification
{
    private $channel;

    public function __construct(string $channel)
    {
        $this->channel = $channel;
    }

    public function via($notifiable)
    {
        return [$this->channel];
    }
}

class NotificationsIntegrationTestThrowingChannel
{
    public function send($notifiable, $notification): void
    {
        throw new \RuntimeException('Delivery failed');
    }
}

class NotificationsIntegrationTestReportingChannel
{
    public function send($notifiable, $notification): void
    {
        event(new NotificationFailed($notifiable, $notification, self::class));
    }
}

class NotificationsIntegrationTestReportingAndThrowingChannel
{
    public function send($notifiable, $notification): void
    {
        event(new NotificationFailed($notifiable, $notification, self::class));

        throw new \RuntimeException('Delivery failed');
    }
}

class NotificationsIntegrationTestNestingChannel
{
    public static $spanAfterNestedSend;

    public function send($notifiable, $notification): void
    {
        \Illuminate\Support\Facades\Notification::route(NotificationsIntegrationTestReportingChannel::class, 'sentry')
            ->notifyNow(new NotificationsIntegrationTestChannelNotification(NotificationsIntegrationTestReportingChannel::class));

        self::$spanAfterNestedSend = SentrySdk::getCurrentHub()->getSpan();
    }
}
