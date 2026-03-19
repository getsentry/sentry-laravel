<?php

namespace Sentry\Laravel\Tests\Jobs\Middleware;

use Closure;
use InvalidArgumentException;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Sentry\EventType;
use Sentry\Laravel\Jobs\Middleware\SentryTracesSampleRate;
use Sentry\Laravel\Tests\TestCase;
use Sentry\SentrySdk;

class SentryTracesSampleRateTest extends TestCase
{
    protected function withTracingEnabled($app): void
    {
        $app['config']->set('sentry.traces_sample_rate', 1.0);
    }

    protected function withTracingDisabled($app): void
    {
        $app['config']->set('sentry.traces_sample_rate', 0.0);
    }

    public function testConstructorRejectsNegativeSampleRate(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SentryTracesSampleRate(-0.1);
    }

    public function testConstructorRejectsSampleRateGreaterThanOne(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SentryTracesSampleRate(1.1);
    }

    public function testConstructorAcceptsBoundarySampleRates(): void
    {
        $zero = new SentryTracesSampleRate(0.0);
        $one = new SentryTracesSampleRate(1.0);

        $this->assertInstanceOf(SentryTracesSampleRate::class, $zero);
        $this->assertInstanceOf(SentryTracesSampleRate::class, $one);
    }

    /**
     * @define-env withTracingEnabled
     */
    #[DefineEnvironment('withTracingEnabled')]
    public function testZeroSampleRateUnsamplesTransaction(): void
    {
        $transaction = $this->startTransaction();

        $this->assertTrue($transaction->getSampled());

        $middleware = new SentryTracesSampleRate(0.0);
        $middleware->handle(new \stdClass, $this->nextMiddleware());

        $this->assertFalse($transaction->getSampled());
    }

    /**
     * @define-env withTracingEnabled
     */
    #[DefineEnvironment('withTracingEnabled')]
    public function testFullSampleRateKeepsTransactionSampled(): void
    {
        $transaction = $this->startTransaction();

        $this->assertTrue($transaction->getSampled());

        $middleware = new SentryTracesSampleRate(1.0);
        $middleware->handle(new \stdClass, $this->nextMiddleware());

        $this->assertTrue($transaction->getSampled());
    }

    /**
     * @define-env withTracingEnabled
     */
    #[DefineEnvironment('withTracingEnabled')]
    public function testDoesNotUpsampleUnsampledTransaction(): void
    {
        $transaction = $this->startTransaction();
        $transaction->setSampled(false);

        $middleware = new SentryTracesSampleRate(1.0);
        $middleware->handle(new \stdClass, $this->nextMiddleware());

        $this->assertFalse($transaction->getSampled());
    }

    /**
     * @define-env withTracingEnabled
     */
    #[DefineEnvironment('withTracingEnabled')]
    public function testDoesNotUpsampleNullSampledTransaction(): void
    {
        $transaction = $this->startTransaction();
        $transaction->setSampled(null);

        $middleware = new SentryTracesSampleRate(1.0);
        $middleware->handle(new \stdClass, $this->nextMiddleware());

        $this->assertNull($transaction->getSampled());
    }

    public function testMiddlewareCallsNextHandler(): void
    {
        $called = false;

        $middleware = new SentryTracesSampleRate(1.0);
        $middleware->handle(new \stdClass, function () use (&$called) {
            $called = true;
        });

        $this->assertTrue($called);
    }

    public function testMiddlewareHandlesNoTransaction(): void
    {
        // No transaction started, should not throw
        $middleware = new SentryTracesSampleRate(0.5);
        $middleware->handle(new \stdClass, $this->nextMiddleware());

        // If we get here without exceptions, the test passes
        $this->assertTrue(true);
    }

    /**
     * @define-env withTracingEnabled
     */
    #[DefineEnvironment('withTracingEnabled')]
    public function testPartialSampleRateEventuallyUnsamplesTransaction(): void
    {
        // With a very low sample rate, running 100 times should produce at least some unsampled results
        $unsampledCount = 0;

        for ($i = 0; $i < 100; $i++) {
            $transaction = $this->startTransaction();

            $middleware = new SentryTracesSampleRate(0.01);
            $middleware->handle(new \stdClass, $this->nextMiddleware());

            if (!$transaction->getSampled()) {
                $unsampledCount++;
            }
        }

        // With a 1% sample rate, we expect most of the 100 runs to be unsampled
        $this->assertGreaterThan(80, $unsampledCount);
    }

    /**
     * @define-env envWithoutDsnSet
     */
    #[DefineEnvironment('envWithoutDsnSet')]
    public function testMiddlewareHandlesHubNotBound(): void
    {
        $middleware = new SentryTracesSampleRate(0.5);
        $middleware->handle(new \stdClass, $this->nextMiddleware());

        // Should not throw when HubInterface is not bound
        $this->assertTrue(true);
    }

    private function nextMiddleware(): Closure
    {
        return function () {
            // no-op
        };
    }
}
