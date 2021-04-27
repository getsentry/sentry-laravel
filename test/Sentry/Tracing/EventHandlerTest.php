<?php

namespace Sentry\Laravel\Tests\Tracing;

use ReflectionClass;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Sentry\Laravel\Tests\ExpectsException;
use Sentry\Laravel\Tracing\EventHandler;

class EventHandlerTest extends TestCase
{
    use ExpectsException;

    public function test_missing_event_handler_throws_exception()
    {
        $this->safeExpectException(RuntimeException::class);

        $handler = new EventHandler($this->app, []);

        $handler->thisIsNotAHandlerAndShouldThrowAnException();
    }

    public function test_all_mapped_event_handlers_exist()
    {
        $this->tryAllEventHandlerMethods(
            $this->getStaticPropertyValueFromClass(EventHandler::class, 'eventHandlerMap')
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
