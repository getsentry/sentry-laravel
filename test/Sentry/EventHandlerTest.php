<?php

namespace Sentry\Laravel\Tests;

class EventHandlerTest extends \Orchestra\Testbench\TestCase
{
    /**
     * @expectedException \RuntimeException
     */
    public function test_missing_event_handler_throws_exception()
    {
        $handler = new \Sentry\Laravel\EventHandler($this->app->events, []);

        $handler->thisIsNotAHandlerAndShouldThrowAnException();
    }
}
