<?php

namespace Sentry\Laravel\Tests\Tracing;

use Sentry\Event;
use Sentry\EventType;
use Sentry\Laravel\Tests\TestCase;
use Sentry\Laravel\Tracing\Middleware;
use Sentry\Tracing\Span;

class BootstrapSpanTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 1.0,
        ]);

        $this->app['router']->get('/bootstrap', static function () {
            return 'ok';
        });
    }

    public function testBootstrapSpanIsRecordedWhenApplicationBootedDuringRequest(): void
    {
        $requestStartTimestamp = microtime(true) - 1;
        $bootedTimestamp = $requestStartTimestamp + 0.5;

        $this->app->make(Middleware::class)->setBootedTimestamp($bootedTimestamp);

        $transaction = $this->getTransactionForRequestStartedAt($requestStartTimestamp);

        $bootstrapSpan = $this->findSpanByOp($transaction, 'app.bootstrap');

        $this->assertNotNull($bootstrapSpan);
        $this->assertSame($requestStartTimestamp, $bootstrapSpan->getStartTimestamp());
        $this->assertSame($bootedTimestamp, $bootstrapSpan->getEndTimestamp());

        $middlewareSpan = $this->findSpanByOp($transaction, 'middleware.handle');

        $this->assertNotNull($middlewareSpan);
        $this->assertSame($bootedTimestamp, $middlewareSpan->getStartTimestamp());
    }

    public function testBootstrapSpanIsSkippedWhenApplicationBootedBeforeRequest(): void
    {
        $requestStartTimestamp = microtime(true);

        // Long-running workers like Octane boot the application once before handling requests
        $this->app->make(Middleware::class)->setBootedTimestamp($requestStartTimestamp - 10);

        $transaction = $this->getTransactionForRequestStartedAt($requestStartTimestamp);

        $this->assertNull($this->findSpanByOp($transaction, 'app.bootstrap'));

        $middlewareSpan = $this->findSpanByOp($transaction, 'middleware.handle');

        $this->assertNotNull($middlewareSpan);
        $this->assertGreaterThanOrEqual($transaction->getStartTimestamp(), $middlewareSpan->getStartTimestamp());

        foreach ($transaction->getSpans() as $span) {
            $this->assertGreaterThanOrEqual($span->getStartTimestamp(), $span->getEndTimestamp(), "Span {$span->getOp()} ends before it starts.");
        }
    }

    private function getTransactionForRequestStartedAt(float $requestStartTimestamp): Event
    {
        $this->withServerVariables(['REQUEST_TIME_FLOAT' => $requestStartTimestamp])
            ->get('/bootstrap')
            ->assertOk();

        $transaction = $this->getLastSentryEvent();

        $this->assertNotNull($transaction);
        $this->assertSame(EventType::transaction(), $transaction->getType());
        $this->assertSame($requestStartTimestamp, $transaction->getStartTimestamp());

        return $transaction;
    }

    private function findSpanByOp(Event $transaction, string $op): ?Span
    {
        foreach ($transaction->getSpans() as $span) {
            if ($span->getOp() === $op) {
                return $span;
            }
        }

        return null;
    }
}
