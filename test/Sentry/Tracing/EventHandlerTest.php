<?php

namespace Sentry\Laravel\Tests\Tracing;

use ReflectionClass;
use Sentry\Laravel\Tests\TestCase;
use Sentry\Laravel\Tracing\BacktraceHelper;
use RuntimeException;
use Sentry\Laravel\Tracing\EventHandler;

class EventHandlerTest extends TestCase
{
    public function testMissingEventHandlerThrowsException(): void
    {
        $this->expectException(RuntimeException::class);

        $handler = new EventHandler([], $this->app->make(BacktraceHelper::class));

        /** @noinspection PhpUndefinedMethodInspection */
        $handler->thisIsNotAHandlerAndShouldThrowAnException();
    }

    public function testAllMappedEventHandlersExist(): void
    {
        $this->tryAllEventHandlerMethods(
            $this->getEventHandlerMapFromEventHandler()
        );
    }

    private function tryAllEventHandlerMethods(array $methods): void
    {
        $handler = new EventHandler([], $this->app->make(BacktraceHelper::class));

        $methods = array_map(static function ($method) {
            return "{$method}Handler";
        }, array_unique(array_values($methods)));

        foreach ($methods as $handlerMethod) {
            $this->assertTrue(method_exists($handler, $handlerMethod));
        }
    }

    private function getEventHandlerMapFromEventHandler()
    {
        $class = new ReflectionClass(EventHandler::class);

        $attributes = $class->getStaticProperties();

        return $attributes['eventHandlerMap'];
    }
}
