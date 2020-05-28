<?php

namespace Sentry\Laravel\Tests;

use Illuminate\Console\Events\CommandStarting;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class CommandInfoInBreadcrumbsTest extends SentryLaravelTestCase
{
    public function testCommandInfoAreRecordedWhenEnabled()
    {
        if ($this->shouldSkip()) {
            $this->markTestSkipped('Laravel version <5.5 does not contain the events tested.');
        }

        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.command_info' => true,
        ]);

        $this->assertTrue($this->app['config']->get('sentry.breadcrumbs.command_info'));

        $this->dispatchCommandStartEvent();

        $lastBreadcrumb = $this->getLastBreadcrumb();

        $this->assertEquals('Starting Artisan command: test:command', $lastBreadcrumb->getMessage());
        $this->assertEquals('--foo=bar', $lastBreadcrumb->getMetadata()['input']);
    }

    public function testCommandInfoAreRecordedWhenDisabled()
    {
        if ($this->shouldSkip()) {
            $this->markTestSkipped('Laravel version <5.5 does not contain the events tested.');
        }

        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.command_info' => false,
        ]);

        $this->assertFalse($this->app['config']->get('sentry.breadcrumbs.command_info'));

        $this->dispatchCommandStartEvent();

        $this->assertEmpty($this->getCurrentBreadcrumbs());
    }

    private function dispatchCommandStartEvent()
    {
        $dispatcher = $this->app['events'];

        $method = method_exists($dispatcher, 'dispatch') ? 'dispatch' : 'fire';

        $this->app['events']->$method(
            CommandStarting::class,
            new CommandStarting(
                'test:command',
                new ArrayInput(['--foo' => 'bar']),
                new BufferedOutput()
            )
        );
    }

    private function shouldSkip()
    {
        return !class_exists(CommandStarting::class);
    }
}
