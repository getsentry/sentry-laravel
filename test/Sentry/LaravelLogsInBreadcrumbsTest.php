<?php

namespace Sentry\Laravel\Tests;

class LaravelLogsInBreadcrumbsTest extends SentryLaravelTestCase
{
    public function testLaravelLogsAreRecordedWhenEnabled()
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.logs' => true,
        ]);

        $this->assertTrue($this->app['config']->get('sentry.breadcrumbs.logs'));

        $this->dispatchLaravelEvent('illuminate.log', [
            $level = 'debug',
            $message = 'test message',
            $context = ['1'],
        ]);

        $lastBreadcrumb = $this->getLastBreadcrumb();

        $this->assertEquals($level, $lastBreadcrumb->getLevel());
        $this->assertEquals($message, $lastBreadcrumb->getMessage());
        $this->assertEquals($context, $lastBreadcrumb->getMetadata()['params']);
    }

    public function testLaravelLogsAreRecordedWhenDisabled()
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
