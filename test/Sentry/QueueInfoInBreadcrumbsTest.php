<?php


namespace Sentry\Laravel\Tests;

use Illuminate\Console\Events\CommandStarting;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class QueueInfoInBreadcrumbsTest extends SentryLaravelTestCase
{
    public function testQueueInfoAreRecordedWhenEnabled()
    {
        if ($this->shouldSkip()) {
            $this->markTestSkipped('Laravel version too low.');
        }
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.queue_info' => true,
        ]);

        $this->assertTrue($this->app['config']->get('sentry.breadcrumbs.queue_info'));

        $this->dispatchCommandStartEvent();

        $breadcrumbs = $this->getCurrentBreadcrumbs();

        /** @var \Sentry\Breadcrumb $lastBreadcrumb */
        $lastBreadcrumb = end($breadcrumbs);

        $this->assertEquals('Invoked Artisan command: test:command', $lastBreadcrumb->getMessage());
        $this->assertEquals('--foo=bar', $lastBreadcrumb->getMetadata()['input']);
    }

    public function testQueueInfoAreRecordedWhenDisabled()
    {
        if ($this->shouldSkip()) {
            $this->markTestSkipped('Laravel version too low.');
        }
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.queue_info' => false,
        ]);

        $this->assertFalse($this->app['config']->get('sentry.breadcrumbs.queue_info'));
        $this->dispatchCommandStartEvent();

        $breadcrumbs = $this->getCurrentBreadcrumbs();
        $this->assertEmpty($breadcrumbs);
    }

    private function dispatchCommandStartEvent()
    {
        $dispatcher = $this->app['events'];
        $method     = method_exists($dispatcher, 'dispatch') ? 'dispatch' : 'fire';
        $this->app['events']->$method(CommandStarting::class, new CommandStarting($command = 'test:command', $input = new ArrayInput(['--foo' => 'bar']), new BufferedOutput()));
    }

    private function shouldSkip()
    {
        return !class_exists(CommandStarting::class);
    }
}
