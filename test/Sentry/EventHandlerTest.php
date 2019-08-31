<?php

namespace Sentry\Laravel\Tests;

class EventHandlerTest extends \Orchestra\Testbench\TestCase
{
    public function test_missing_event_handler_throws_exception()
    {
        $this->expectException(\RuntimeException::class);

        $handler = new \Sentry\Laravel\EventHandler($this->app->events, []);

        $handler->thisIsNotAHandlerAndShouldThrowAnException();
    }
}
