<?php

class EventHandlerTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @expectedException \RuntimeException
     */
    public function test_missing_event_handler_throws_exception()
    {
        $handler = new \Sentry\Laravel\EventHandler([]);

        $handler->thisIsNotAHandlerAndShouldThrowAnException();
    }
}
