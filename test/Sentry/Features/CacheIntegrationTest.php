<?php

namespace Sentry\Laravel\Tests\Features;

use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Session\NullSessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Sentry\Laravel\Tests\TestCase;
use Sentry\Tracing\Span;
use Sentry\Laravel\Features\CacheIntegration;
use RuntimeException;

class CacheIntegrationTest extends TestCase
{
    protected $defaultSetupConfig = [
        'session.driver' => 'array',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure that session keys can be detected in the tests that are running from the console
        CacheIntegration::$detectSessionKeyOnConsole = true;
    }

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
        // Start a session properly in the test environment
        $this->startSession();
        $sessionId = $this->app['session']->getId();

        // Use the session ID as a cache key
        Cache::put($sessionId, 'session-data');

        $breadcrumb = $this->getLastSentryBreadcrumb();
        $this->assertEquals('Written: {sessionKey}', $breadcrumb->getMessage());

        Cache::get($sessionId);

        $breadcrumb = $this->getLastSentryBreadcrumb();
        $this->assertEquals('Read: {sessionKey}', $breadcrumb->getMessage());
    }

    public function testCacheBreadcrumbDoesNotReplaceNonSessionKeys(): void
    {
        Cache::put('regular-key', 'value');

        $breadcrumb = $this->getLastSentryBreadcrumb();
        $this->assertEquals('Written: regular-key', $breadcrumb->getMessage());
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

        $this->startSession();
        $sessionId = $this->app['session']->getId();

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

        // Start a session properly in the test environment
        $this->startSession();
        $sessionId = $this->app['session']->getId();

        $span = $this->executeAndReturnMostRecentSpan(function () use ($sessionId) {
            Cache::get([$sessionId, 'regular-key', $sessionId . '_another']);
        });

        $this->assertEquals('cache.get', $span->getOp());
        $this->assertEquals('{sessionKey}, regular-key, ' . $sessionId . '_another', $span->getDescription());
        $this->assertEquals(['{sessionKey}', 'regular-key', $sessionId . '_another'], $span->getData()['cache.key']);
    }

    public function testCacheBackedSessionReadMissSpanIsRecordedAsSessionGet(): void
    {
        $this->markSkippedIfTracingEventsNotAvailable();

        $sessionId = str_repeat('a', 40);
        $session = $this->createCacheBackedSession($sessionId);

        $span = $this->executeAndReturnMostRecentSpan(static function () use ($session) {
            $session->start();
        });

        $this->assertEquals('session.get', $span->getOp());
        $this->assertEquals('{sessionKey}', $span->getDescription());
        $this->assertEquals(['{sessionKey}'], $span->getData()['session.key']);
        $this->assertFalse($span->getData()['session.hit']);
        $this->assertFalse(isset($span->getData()['cache.hit']));
    }

    public function testCacheBackedSessionReadHitSpanAndBreadcrumbAreRecordedAsSession(): void
    {
        $this->markSkippedIfTracingEventsNotAvailable();

        $sessionId = str_repeat('a', 40);
        $session = $this->createCacheBackedSession($sessionId);

        Cache::put($sessionId, serialize(['foo' => 'bar']));

        $span = $this->executeAndReturnMostRecentSpan(static function () use ($session) {
            $session->start();
        });

        $this->assertEquals('session.get', $span->getOp());
        $this->assertEquals('{sessionKey}', $span->getDescription());
        $this->assertEquals(['{sessionKey}'], $span->getData()['session.key']);
        $this->assertTrue($span->getData()['session.hit']);

        $breadcrumb = $this->getLastSentryBreadcrumb();
        $this->assertEquals('session', $breadcrumb->getCategory());
        $this->assertEquals('Read: {sessionKey}', $breadcrumb->getMessage());
    }

    public function testCacheBackedSessionWriteSpanIsRecordedAsSessionPut(): void
    {
        $this->markSkippedIfTracingEventsNotAvailable();

        $sessionId = str_repeat('a', 40);
        $session = $this->createCacheBackedSession($sessionId);

        $span = $this->executeAndReturnMostRecentSpan(static function () use ($session) {
            $session->put('foo', 'bar');
            $session->save();
        });

        $this->assertEquals('session.put', $span->getOp());
        $this->assertEquals('{sessionKey}', $span->getDescription());
        $this->assertEquals(['{sessionKey}'], $span->getData()['session.key']);
        $this->assertEquals(7200, $span->getData()['session.ttl']);
        $this->assertTrue($span->getData()['session.success']);
        $this->assertFalse(isset($span->getData()['cache.success']));
    }

    public function testCacheBackedSessionDestroySpanIsRecordedAsSessionRemove(): void
    {
        $this->markSkippedIfTracingEventsNotAvailable();

        $sessionId = str_repeat('a', 40);
        $session = $this->createCacheBackedSession($sessionId);

        $span = $this->executeAndReturnMostRecentSpan(static function () use ($session) {
            $session->migrate(true);
        });

        $this->assertEquals('session.remove', $span->getOp());
        $this->assertEquals('{sessionKey}', $span->getDescription());
        $this->assertEquals(['{sessionKey}'], $span->getData()['session.key']);
    }

    public function testBatchCacheOperationContainingSessionKeyIsStillRecordedAsCache(): void
    {
        $this->markSkippedIfTracingEventsNotAvailable();

        $sessionId = str_repeat('a', 40);
        $this->createCacheBackedSession($sessionId);

        $span = $this->executeAndReturnMostRecentSpan(static function () use ($sessionId) {
            Cache::get([$sessionId, 'regular-key']);
        });

        $this->assertEquals('cache.get', $span->getOp());
        $this->assertEquals('{sessionKey}, regular-key', $span->getDescription());
        $this->assertEquals(['{sessionKey}', 'regular-key'], $span->getData()['cache.key']);
        $this->assertFalse(isset($span->getData()['session.key']));
    }

    public function testCacheBreadcrumbReplacesSessionKeyFromRequestCookieWithoutResolvingSessionStore(): void
    {
        $sessionId = str_repeat('a', 40);

        $this->app['request']->cookies->set($this->app['config']->get('session.cookie'), $sessionId);

        CacheIntegrationCacheAccessingSessionStore::$constructionCount = 0;

        $this->app->singleton('session.store', static function () {
            CacheIntegrationCacheAccessingSessionStore::$constructionCount++;

            throw new RuntimeException('The session store should not be resolved when the request cookie is available.');
        });

        Cache::get($sessionId);

        $this->assertSame(0, CacheIntegrationCacheAccessingSessionStore::$constructionCount);
        $this->assertEquals('Missed: {sessionKey}', $this->getLastSentryBreadcrumb()->getMessage());
    }

    public function testCacheBreadcrumbUsesResolvedSessionStoreBeforeRequestCookie(): void
    {
        $cookieSessionId = str_repeat('a', 40);

        $this->app['request']->cookies->set($this->app['config']->get('session.cookie'), $cookieSessionId);

        $this->startSession();

        $sessionStoreSessionId = $this->app['session.store']->getId();

        $this->assertNotSame($cookieSessionId, $sessionStoreSessionId);

        Cache::get($sessionStoreSessionId);

        $this->assertEquals('Missed: {sessionKey}', $this->getLastSentryBreadcrumb()->getMessage());
    }

    public function testCacheSessionKeyDetectionDoesNotReenterSessionStoreConstruction(): void
    {
        CacheIntegrationCacheAccessingSessionStore::$cacheKey = 'browser-detect-like-cache-key';
        CacheIntegrationCacheAccessingSessionStore::$constructionCount = 0;

        $this->app->singleton('session.store', static function () {
            return new CacheIntegrationCacheAccessingSessionStore();
        });

        Cache::get('outer-cache-key');

        $this->assertSame(1, CacheIntegrationCacheAccessingSessionStore::$constructionCount);
        $this->assertEquals('Missed: outer-cache-key', $this->getLastSentryBreadcrumb()->getMessage());
    }

    public function testCacheOperationDoesNotStartSessionPrematurely(): void
    {
        $this->markSkippedIfTracingEventsNotAvailable();

        // Don't start a session to ensure it's not started

        $span = $this->executeAndReturnMostRecentSpan(function () {
            Cache::get('some-key');
        });

        // Check that session was not started
        $this->assertFalse($this->app['session']->isStarted());

        // And the key should not be replaced
        $this->assertEquals('some-key', $span->getDescription());
    }

    public function testRedisParametersAreRecordedWhenPIIShouldBeSent(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.redis_commands' => true,
            'sentry.send_default_pii' => true,
        ]);

        $span = $this->executeRedisCommandAndReturnSpan();

        $this->assertSame('SET foo', $span->getDescription());
        $this->assertSame(['foo', 'bar'], $span->getData()['db.redis.parameters']);
    }

    public function testRedisParametersAreNotRecordedWhenPIIShouldNotBeSent(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.redis_commands' => true,
            'sentry.send_default_pii' => false,
        ]);

        $span = $this->executeRedisCommandAndReturnSpan();

        $this->assertArrayNotHasKey('db.redis.parameters', $span->getData());
    }

    public function testRedisParametersAreRecordedWithDataCollection(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.redis_commands' => true,
            'sentry.send_default_pii' => false,
            'sentry.data_collection' => [],
        ]);

        $span = $this->executeRedisCommandAndReturnSpan();

        $this->assertSame(['foo', 'bar'], $span->getData()['db.redis.parameters']);
    }

    public function testRedisParametersAreNotRecordedWhenDatabaseQueryDataIsDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.redis_commands' => true,
            'sentry.send_default_pii' => true,
            'sentry.data_collection' => [
                'database_query_data' => false,
            ],
        ]);

        $span = $this->executeRedisCommandAndReturnSpan();

        $this->assertArrayNotHasKey('db.redis.parameters', $span->getData());
    }

    private function executeRedisCommandAndReturnSpan(): Span
    {
        return $this->executeAndReturnMostRecentSpan(function () {
            $this->dispatchLaravelEvent(new CommandExecuted('set', ['foo', 'bar'], 1.0, Mockery::mock(Connection::class, [
                'getName' => 'default',
            ])));
        });
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

    private function createCacheBackedSession(string $sessionId): Store
    {
        $sessionDriver = 'cache-backed-test';

        $this->app['session']->extend($sessionDriver, function ($app) {
            return new CacheBasedSessionHandler($app['cache']->store('array'), 120);
        });

        $this->app['config']->set('session.driver', $sessionDriver);
        $this->app['session']->forgetDrivers();

        /** @var Store $session */
        $session = $this->app['session']->driver();
        $session->setId($sessionId);

        return $session;
    }
}

class CacheIntegrationCacheAccessingSessionStore extends Store
{
    /** @var int */
    public static $constructionCount = 0;

    /** @var string */
    public static $cacheKey = 'browser-detect-like-cache-key';

    public function __construct()
    {
        self::$constructionCount++;

        if (self::$constructionCount > 3) {
            throw new RuntimeException('Session store construction re-entered too many times.');
        }

        Cache::remember(self::$cacheKey, 60, static function () {
            return 'bot-detection-result';
        });

        parent::__construct('laravel_session', new NullSessionHandler);
    }
}
