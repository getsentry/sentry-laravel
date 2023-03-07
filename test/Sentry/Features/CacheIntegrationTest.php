<?php

namespace Sentry\Features;

use Illuminate\Support\Facades\Cache;
use Sentry\Laravel\Tests\TestCase;

class CacheIntegrationTest extends TestCase
{
    public function testCacheBreadcrumbForWriteAndHitIsRecorded(): void
    {
        Cache::put($key = 'foo', 'bar');

        $this->assertEquals("Written: {$key}", $this->getLastBreadcrumb()->getMessage());

        Cache::get('foo');

        $this->assertEquals("Read: {$key}", $this->getLastBreadcrumb()->getMessage());
    }

    public function testCacheBreadcrumbForWriteAndForgetIsRecorded(): void
    {
        Cache::put($key = 'foo', 'bar');

        $this->assertEquals("Written: {$key}", $this->getLastBreadcrumb()->getMessage());

        Cache::forget($key);

        $this->assertEquals("Forgotten: {$key}", $this->getLastBreadcrumb()->getMessage());
    }

    public function testCacheBreadcrumbForMissIsRecorded(): void
    {
        Cache::get($key = 'foo');

        $this->assertEquals("Missed: {$key}", $this->getLastBreadcrumb()->getMessage());
    }

    public function testCacheBreadcrumIsNotRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.cache' => false,
        ]);

        $this->assertFalse($this->app['config']->get('sentry.breadcrumbs.cache'));

        Cache::get('foo');

        $this->assertEmpty($this->getCurrentBreadcrumbs());
    }
}
