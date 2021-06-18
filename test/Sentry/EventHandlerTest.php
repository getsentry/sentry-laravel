<?php

namespace Sentry\Laravel\Tests;

use ReflectionClass;
use RuntimeException;
use Sentry\Laravel\EventHandler;
use Orchestra\Testbench\TestCase;

class EventHandlerTest extends TestCase
{
    use ExpectsException;

    public function test_missing_event_handler_throws_exception()
    {
        $handler = new EventHandler($this->app, []);

        $this->safeExpectException(RuntimeException::class);

        $handler->thisIsNotAHandlerAndShouldThrowAnException();
    }

    public function test_all_mapped_event_handlers_exist()
    {
        $this->tryAllEventHandlerMethods(
            $this->getStaticPropertyValueFromClass(EventHandler::class, 'eventHandlerMap')
        );
    }

    public function test_all_mapped_auth_event_handlers_exist()
    {
        $this->tryAllEventHandlerMethods(
            $this->getStaticPropertyValueFromClass(EventHandler::class, 'authEventHandlerMap')
        );
    }

    public function test_all_mapped_queue_event_handlers_exist()
    {
        $this->tryAllEventHandlerMethods(
            $this->getStaticPropertyValueFromClass(EventHandler::class, 'queueEventHandlerMap')
        );
    }

    public function test_all_mapped_octane_event_handlers_exist()
    {
        $this->tryAllEventHandlerMethods(
            $this->getStaticPropertyValueFromClass(EventHandler::class, 'octaneEventHandlerMap')
        );
    }

    private function tryAllEventHandlerMethods(array $methods): void
    {
        $handler = new EventHandler($this->app, []);

        $methods = array_map(static function ($method) {
            return "{$method}Handler";
        }, array_unique(array_values($methods)));

        foreach ($methods as $handlerMethod) {
            $this->assertTrue(method_exists($handler, $handlerMethod));
        }
    }

    private function getStaticPropertyValueFromClass($className, $attributeName)
    {
        $class = new ReflectionClass($className);

        $attributes = $class->getStaticProperties();

        return $attributes[$attributeName];
    }
}
