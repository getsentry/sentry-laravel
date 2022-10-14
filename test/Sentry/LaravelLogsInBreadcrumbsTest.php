<?php

namespace Sentry\Laravel\Tests;

use Illuminate\Log\Events\MessageLogged;
use Mockery;

class LaravelLogsInBreadcrumbsTest extends TestCase
{
    public function testLaravelLogsAreRecordedWhenEnabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.logs' => true,
        ]);

        $this->assertTrue($this->app['config']->get('sentry.breadcrumbs.logs'));

        $this->dispatchLaravelEvent(new MessageLogged(
            $level = 'debug',
            $message = 'test message',
            $context = ['1']
        ));

        $lastBreadcrumb = $this->getLastBreadcrumb();

        $this->assertEquals($level, $lastBreadcrumb->getLevel());
        $this->assertEquals($message, $lastBreadcrumb->getMessage());
        $this->assertEquals($context, $lastBreadcrumb->getMetadata());
    }

    public function testLaravelLogsAreRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.logs' => false,
        ]);

        $this->assertFalse($this->app['config']->get('sentry.breadcrumbs.logs'));

        $this->dispatchLaravelEvent('illuminate.log', [
            $level = 'debug',
            $message = 'test message',
            $context = ['1'],
        ]);

        $this->assertEmpty($this->getCurrentBreadcrumbs());
    }
}
