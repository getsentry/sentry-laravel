<?php

namespace Sentry\Laravel\Tests\Tracing;

use ReflectionClass;
use Orchestra\Testbench\TestCase;
use Sentry\Laravel\Tracing\EventHandler;
use Sentry\SentrySdk;
use Sentry\Tracing\TransactionContext;

class EventHandlerTest extends TestCase
{
    /**
     * @expectedException \RuntimeException
     */
    public function test_missing_event_handler_throws_exception()
    {
        $handler = new EventHandler($this->app->events);

        $handler->thisIsNotAHandlerAndShouldThrowAnException();
    }

    public function test_all_mapped_event_handlers_exist()
    {
        $this->tryAllEventHandlerMethods(
            $this->getStaticPropertyValueFromClass(EventHandler::class, 'eventHandlerMap')
        );
    }

    public function test_handlers_are_not_called_when_no_transaction()
    {
        SentrySdk::getCurrentHub()->setSpan(null);

        $eventHandlerMock = $this->mock(EventHandler::class)->makePartial();

        $eventHandlerMock->shouldReceive('__call')->withArgs(['queryHandler', []]);

        $eventHandlerMock->query();
    }

    public function test_handlers_are_called_when_transaction_is_present()
    {
        $transaction = SentrySdk::getCurrentHub()->startTransaction(new TransactionContext());
        SentrySdk::getCurrentHub()->setSpan($transaction);

        $eventHandlerMock = $this->mock(EventHandler::class)->makePartial()->shouldAllowMockingProtectedMethods();

        $eventHandlerMock->shouldReceive('queryHandler')->withArgs(['', [], 0, ''])->once();

        $eventHandlerMock->query('', [], 0, '');
    }

    private function tryAllEventHandlerMethods(array $methods): void
    {
        $handler = new EventHandler($this->app->events, []);

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