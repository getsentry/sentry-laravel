<?php

namespace Sentry\Laravel\Tests\Features;

use Closure;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\Cache;
use Sentry\Laravel\Tests\TestCase;

class CacheIntegrationTest extends TestCase
{
    public function testCacheBreadcrumbForWriteAndHitIsRecorded(): void
    {
        Cache::put($key = 'foo', 'bar');

        $this->assertEquals("Written: {$key}", $this->getLastSentryBreadcrumb()->getMessage());

        Cache::get('foo');

        $this->assertEquals("Read: {$key}", $this->getLastSentryBreadcrumb()->getMessage());
    }

    public function testCacheBreadcrumbForWriteAndForgetIsRecorded(): void
    {
        Cache::put($key = 'foo', 'bar');

        $this->assertEquals("Written: {$key}", $this->getLastSentryBreadcrumb()->getMessage());

        Cache::forget($key);

        $this->assertEquals("Forgotten: {$key}", $this->getLastSentryBreadcrumb()->getMessage());
    }

    public function testCacheBreadcrumbForMissIsRecorded(): void
    {
        Cache::get($key = 'foo');

        $this->assertEquals("Missed: {$key}", $this->getLastSentryBreadcrumb()->getMessage());
    }

    public function testCacheBreadcrumbIsNotRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.cache' => false,
        ]);

        $this->assertFalse($this->app['config']->get('sentry.breadcrumbs.cache'));

        Cache::get('foo');

        $this->assertEmpty($this->getCurrentSentryBreadcrumbs());
    }

    public function testCacheMissIsRecordedForRedisCommand(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.redis_commands' => true,
        ]);

        $connection = $this->mockRedisConnection();

        $transaction = $this->startTransaction();

        $key = 'foo';

        // The `Cache::get()` method would trigger a Redis command before the `CacheHit` or `CacheMissed` event
        // (these events are responsible for setting the tested `cache.hit` data on the Redis span. We will fake
        // the `CommandExecuted` event before executing a `Cache::*()` method to not need a Redis server running.

        $this->dispatchLaravelEvent(new CommandExecuted('get', [$key], 0.1, $connection));

        Cache::get($key);

        Cache::set($key, 'bar');

        $this->dispatchLaravelEvent(new CommandExecuted('get', [$key], 0.1, $connection));

        Cache::get($key);

        $this->dispatchLaravelEvent(new CommandExecuted('del', [$key], 0.1, $connection));

        Cache::forget($key);

        [, $cacheMissSpan, $cacheHitSpan, $otherCommandSpan] = $transaction->getSpanRecorder()->getSpans();

        $this->assertFalse($cacheMissSpan->getData()['cache.hit']);
        $this->assertTrue($cacheHitSpan->getData()['cache.hit']);
        $this->assertArrayNotHasKey('cache.hit', $otherCommandSpan->getData());
    }

    private function mockRedisConnection(): Connection
    {
        return new class extends Connection {
            public function createSubscription($channels, Closure $callback, $method = 'subscribe')
            {
                // We have no need for this method in this test.
            }

            public function getName()
            {
                return 'mock-redis-connection';
            }
        };
    }
}
