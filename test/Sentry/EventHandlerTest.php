<?php

namespace Sentry\Laravel\Tests;

use ReflectionClass;
use RuntimeException;
use Sentry\Laravel\EventHandler;
use Orchestra\Testbench\TestCase;

class EventHandlerTest extends TestCase
{
    public function testMissingEventHandlerThrowsException(): void
    {
        $handler = new EventHandler($this->app, []);

        $this->expectException(RuntimeException::class);

        /** @noinspection PhpUndefinedMethodInspection */
        $handler->thisIsNotAHandlerAndShouldThrowAnException();
    }

    public function testAllMappedEventHandlersExist(): void
    {
        $this->tryAllEventHandlerMethods(
            $this->getStaticPropertyValueFromClass(EventHandler::class, 'eventHandlerMap')
        );
    }

    public function testAllMappedAuthEventHandlersExist(): void
    {
        $this->tryAllEventHandlerMethods(
            $this->getStaticPropertyValueFromClass(EventHandler::class, 'authEventHandlerMap')
        );
    }

    public function testAllMappedQueueEventHandlersExist(): void
    {
        $this->tryAllEventHandlerMethods(
            $this->getStaticPropertyValueFromClass(EventHandler::class, 'queueEventHandlerMap')
        );
    }

    public function testAllMappedOctaneEventHandlersExist(): void
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
