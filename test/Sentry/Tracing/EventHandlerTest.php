<?php

namespace Sentry\Laravel\Tests\Tracing;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Mockery;
use ReflectionClass;
use RuntimeException;
use Sentry\Laravel\Tests\TestCase;
use Sentry\Laravel\Tracing\EventHandler;

class EventHandlerTest extends TestCase
{
    public function testMissingEventHandlerThrowsException(): void
    {
        $this->expectException(RuntimeException::class);

        $handler = new EventHandler([]);

        /** @noinspection PhpUndefinedMethodInspection */
        $handler->thisIsNotAHandlerAndShouldThrowAnException();
    }

    public function testAllMappedEventHandlersExist(): void
    {
        $this->tryAllEventHandlerMethods(
            $this->getEventHandlerMapFromEventHandler()
        );
    }

    public function testSqlBindingsAreRecordedWhenEnabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 1,
            'sentry.tracing.sql_queries' => true,
            'sentry.tracing.sql_bindings' => true,
        ]);

        $this->assertTrue($this->app['config']->get('sentry.tracing.sql_bindings'));

        $this->startTransaction();

        $this->dispatchLaravelEvent(new QueryExecuted(
            $query = 'SELECT * FROM spans WHERE bindings = ?;',
            $bindings = ['1'],
            10,
            $this->getMockedConnection()
        ));

        $span = $this->getLastSentrySpan();

        $this->assertEquals($query, $span->getDescription());
        $this->assertEquals($bindings, $span->getData()['db.sql.bindings']);
    }

    public function testSqlBindingsAreRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 1,
            'sentry.tracing.sql_queries' => true,
            'sentry.tracing.sql_bindings' => false,
        ]);

        $this->assertFalse($this->app['config']->get('sentry.tracing.sql_bindings'));

        $this->startTransaction();

        $this->dispatchLaravelEvent(new QueryExecuted(
            $query = 'SELECT * FROM spans WHERE bindings = ?;',
            $bindings = ['1'],
            10,
            $this->getMockedConnection()
        ));

        $span = $this->getLastSentrySpan();

        $this->assertEquals($query, $span->getDescription());
        $this->assertFalse(isset($span->getData()['db.sql.bindings']));
    }

    private function tryAllEventHandlerMethods(array $methods): void
    {
        $handler = new EventHandler([]);

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

    private function getMockedConnection()
    {
        $mock = Mockery::mock(Connection::class);
        $mock->shouldReceive('getName')->andReturn('test');
        $mock->shouldReceive('getDatabaseName')->andReturn('test');
        $mock->shouldReceive('getDriverName')->andReturn('mysql');
        $mock->shouldReceive('getConfig')->andReturn(['host' => '127.0.0.1', 'port' => 3306]);

        return $mock;
    }
}
