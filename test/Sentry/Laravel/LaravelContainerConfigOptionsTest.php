<?php

namespace Sentry\Laravel\Tests\Laravel;

use Sentry\Laravel\Tests\TestCase;
use Sentry\Logger\DebugFileLogger;
use Sentry\State\HubInterface;

class LaravelContainerConfigOptionsTest extends TestCase
{
    public function testOrgIdIsNullByDefault(): void
    {
        $orgId = app(HubInterface::class)->getClient()->getOptions()->getOrgId();

        $this->assertNull($orgId);
    }

    public function testOrgIdIsResolvedFromConfig(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.org_id' => 42,
        ]);

        $orgId = app(HubInterface::class)->getClient()->getOptions()->getOrgId();

        $this->assertSame(42, $orgId);
    }

    public function testStrictTraceContinuationIsDisabledByDefault(): void
    {
        $enabled = app(HubInterface::class)->getClient()->getOptions()->isStrictTraceContinuationEnabled();

        $this->assertFalse($enabled);
    }

    public function testStrictTraceContinuationIsResolvedFromConfig(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.strict_trace_continuation' => true,
        ]);

        $enabled = app(HubInterface::class)->getClient()->getOptions()->isStrictTraceContinuationEnabled();

        $this->assertTrue($enabled);
    }

    public function testLogFlushThresholdIsNullByDefault(): void
    {
        $logFlushThreshold = app(HubInterface::class)->getClient()->getOptions()->getLogFlushThreshold();

        $this->assertNull($logFlushThreshold);
    }

    public function testLogFlushThresholdIsResolvedFromConfig(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.log_flush_threshold' => 2,
        ]);

        $logFlushThreshold = app(HubInterface::class)->getClient()->getOptions()->getLogFlushThreshold();

        $this->assertSame(2, $logFlushThreshold);
    }

    public function testLoggerIsNullByDefault(): void
    {
        $logger = app(HubInterface::class)->getClient()->getOptions()->getLogger();

        $this->assertNull($logger);
    }

    public function testLoggerIsResolvedFromDefaultSingleton(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.logger' => DebugFileLogger::class,
        ]);

        $logger = app(HubInterface::class)->getClient()->getOptions()->getLogger();

        $this->assertInstanceOf(DebugFileLogger::class, $logger);
    }
}
