<?php

namespace Sentry\Laravel\Tests\Features;

use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Support\Facades\Cache;
use Sentry\Laravel\Tests\TestCase;
use Sentry\Tracing\Span;

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

    public function testCacheBreadcrumbReplacesSessionKeyWithPlaceholder(): void
    {
        // Start a session
        $this->app['request']->setLaravelSession($session = $this->app['session.store']);
        $session->start();
        $sessionId = $session->getId();

        // Use the session ID as a cache key
        Cache::put($sessionId, 'session-data');

        $breadcrumb = $this->getLastSentryBreadcrumb();
        $this->assertEquals("Written: {sessionKey}", $breadcrumb->getMessage());

        Cache::get($sessionId);

        $breadcrumb = $this->getLastSentryBreadcrumb();
        $this->assertEquals("Read: {sessionKey}", $breadcrumb->getMessage());
    }

    public function testCacheBreadcrumbDoesNotReplaceNonSessionKeys(): void
    {
        Cache::put('regular-key', 'value');

        $breadcrumb = $this->getLastSentryBreadcrumb();
        $this->assertEquals("Written: regular-key", $breadcrumb->getMessage());
    }

    public function testCacheGetSpanIsRecorded(): void
    {
        $this->markSkippedIfTracingEventsNotAvailable();

        $span = $this->executeAndReturnMostRecentSpan(function () {
            Cache::get('foo');
        });

        $this->assertEquals('cache.get', $span->getOp());
        $this->assertEquals('foo', $span->getDescription());
        $this->assertEquals(['foo'], $span->getData()['cache.key']);
        $this->assertFalse($span->getData()['cache.hit']);
    }

    public function testCacheGetSpanIsRecordedForBatchOperation(): void
    {
        $this->markSkippedIfTracingEventsNotAvailable();

        $span = $this->executeAndReturnMostRecentSpan(function () {
            Cache::get(['foo', 'bar']);
        });

        $this->assertEquals('cache.get', $span->getOp());
        $this->assertEquals('foo, bar', $span->getDescription());
        $this->assertEquals(['foo', 'bar'], $span->getData()['cache.key']);
    }

    public function testCacheGetSpanIsRecordedForMultipleOperation(): void
    {
        $this->markSkippedIfTracingEventsNotAvailable();

        $span = $this->executeAndReturnMostRecentSpan(function () {
            Cache::getMultiple(['foo', 'bar']);
        });

        $this->assertEquals('cache.get', $span->getOp());
        $this->assertEquals('foo, bar', $span->getDescription());
        $this->assertEquals(['foo', 'bar'], $span->getData()['cache.key']);
    }

    public function testCacheGetSpanIsRecordedWithCorrectHitData(): void
    {
        $this->markSkippedIfTracingEventsNotAvailable();

        $span = $this->executeAndReturnMostRecentSpan(function () {
            Cache::put('foo', 'bar');
            Cache::get('foo');
        });

        $this->assertEquals('cache.get', $span->getOp());
        $this->assertEquals('foo', $span->getDescription());
        $this->assertEquals(['foo'], $span->getData()['cache.key']);
        $this->assertTrue($span->getData()['cache.hit']);
    }

    public function testCachePutSpanIsRecorded(): void
    {
        $this->markSkippedIfTracingEventsNotAvailable();

        $span = $this->executeAndReturnMostRecentSpan(function () {
            Cache::put('foo', 'bar', 99);
        });

        $this->assertEquals('cache.put', $span->getOp());
        $this->assertEquals('foo', $span->getDescription());
        $this->assertEquals(['foo'], $span->getData()['cache.key']);
        $this->assertEquals(99, $span->getData()['cache.ttl']);
    }

    public function testCachePutSpanIsRecordedForManyOperation(): void
    {
        $this->markSkippedIfTracingEventsNotAvailable();

        $span = $this->executeAndReturnMostRecentSpan(function () {
            Cache::putMany(['foo' => 'bar', 'baz' => 'qux'], 99);
        });

        $this->assertEquals('cache.put', $span->getOp());
        $this->assertEquals('foo, baz', $span->getDescription());
        $this->assertEquals(['foo', 'baz'], $span->getData()['cache.key']);
        $this->assertEquals(99, $span->getData()['cache.ttl']);
    }

    public function testCacheRemoveSpanIsRecorded(): void
    {
        $this->markSkippedIfTracingEventsNotAvailable();

        $span = $this->executeAndReturnMostRecentSpan(function () {
            Cache::forget('foo');
        });

        $this->assertEquals('cache.remove', $span->getOp());
        $this->assertEquals('foo', $span->getDescription());
        $this->assertEquals(['foo'], $span->getData()['cache.key']);
    }

    public function testCacheSpanReplacesSessionKeyWithPlaceholder(): void
    {
        $this->markSkippedIfTracingEventsNotAvailable();

        // Start a session
        $this->app['request']->setLaravelSession($session = $this->app['session.store']);
        $session->start();
        $sessionId = $session->getId();

        $span = $this->executeAndReturnMostRecentSpan(function () use ($sessionId) {
            Cache::get($sessionId);
        });

        $this->assertEquals('cache.get', $span->getOp());
        $this->assertEquals('{sessionKey}', $span->getDescription());
        $this->assertEquals(['{sessionKey}'], $span->getData()['cache.key']);
    }

    public function testCacheSpanReplacesMultipleSessionKeysWithPlaceholder(): void
    {
        $this->markSkippedIfTracingEventsNotAvailable();

        // Start a session
        $this->app['request']->setLaravelSession($session = $this->app['session.store']);
        $session->start();
        $sessionId = $session->getId();

        $span = $this->executeAndReturnMostRecentSpan(function () use ($sessionId) {
            Cache::get([$sessionId, 'regular-key', $sessionId . '_another']);
        });

        $this->assertEquals('cache.get', $span->getOp());
        $this->assertEquals('{sessionKey}, regular-key, {sessionKey}', $span->getDescription());
        $this->assertEquals(['{sessionKey}', 'regular-key', '{sessionKey}'], $span->getData()['cache.key']);
    }

    public function testCacheOperationDoesNotStartSessionPrematurely(): void
    {
        $this->markSkippedIfTracingEventsNotAvailable();

        // Make sure session is not started
        $this->assertFalse($this->app['session.store']->isStarted());

        $span = $this->executeAndReturnMostRecentSpan(function () {
            Cache::get('some-key');
        });

        // Session should still not be started
        $this->assertFalse($this->app['session.store']->isStarted());
        
        // And the key should not be replaced
        $this->assertEquals('some-key', $span->getDescription());
    }

    private function markSkippedIfTracingEventsNotAvailable(): void
    {
        if (class_exists(RetrievingKey::class)) {
            return;
        }

        $this->markTestSkipped('The required cache events are not available in this Laravel version');
    }

    private function executeAndReturnMostRecentSpan(callable $callable): Span
    {
        $transaction = $this->startTransaction();

        $callable();

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertTrue(count($spans) >= 2);

        return array_pop($spans);
    }
}
