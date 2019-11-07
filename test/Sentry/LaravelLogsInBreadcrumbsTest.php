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

        $breadcrumbs = $this->getCurrentBreadcrumbs();

        /** @var \Sentry\Breadcrumb $lastBreadcrumb */
        $lastBreadcrumb = end($breadcrumbs);

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

        $breadcrumbs = $this->getCurrentBreadcrumbs();
        $this->assertEmpty($breadcrumbs);
    }
}
