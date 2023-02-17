<?php

namespace Sentry\Laravel\Tests;

use Monolog\Handler\FingersCrossedHandler;
use Sentry\Event;
use Sentry\Laravel\LogChannel;
use Sentry\Laravel\SentryHandler;

class LogChannelTest extends TestCase
{
    public function testCreatingHandlerWithoutActionLevelConfig(): void
    {
        $logChannel = new LogChannel($this->app);

        $logger = $logChannel();

        $this->assertContainsOnlyInstancesOf(SentryHandler::class, $logger->getHandlers());
    }

    public function testCreatingHandlerWithActionLevelConfig(): void
    {
        $logChannel = new LogChannel($this->app);

        $logger = $logChannel(['action_level' => 'critical']);

        $this->assertContainsOnlyInstancesOf(FingersCrossedHandler::class, $logger->getHandlers());

        $currentHandler = current($logger->getHandlers());

        $this->assertInstanceOf(SentryHandler::class, $currentHandler->getHandler());

        $loggerWithoutActionLevel = $logChannel(['action_level' => null]);

        $this->assertContainsOnlyInstancesOf(SentryHandler::class, $loggerWithoutActionLevel->getHandlers());
    }

    /**
     * @dataProvider handlerDataProvider
     */
    public function testHandlerWritingExpectedEventsAndContext(array $context, callable $asserter): void
    {
        $logChannel = new LogChannel($this->app);

        $logger = $logChannel();

        $logger->error('test message', $context);

        $lastEvent = $this->getLastEvent();

        $this->assertNotNull($lastEvent);
        $this->assertEquals('test message', $lastEvent->getMessage());
        $this->assertEquals('error', $lastEvent->getLevel());

        $asserter($lastEvent);
    }

    public function handlerDataProvider(): iterable
    {
        $context = ['foo' => 'bar'];

        yield [
            $context,
            function (Event $event) use ($context) {
                $this->assertEquals($context, $event->getExtra()['log_context']);
            },
        ];

        $context = ['fingerprint' => ['foo', 'bar']];

        yield [
            $context,
            function (Event $event) use ($context) {
                $this->assertEquals($context['fingerprint'], $event->getFingerprint());
                $this->assertEmpty($event->getExtra());
            },
        ];

        $context = ['user' => 'invalid value'];

        yield [
            $context,
            function (Event $event) use ($context) {
                $this->assertNull($event->getUser());
                $this->assertEquals($context, $event->getExtra()['log_context']);
            },
        ];

        $context = ['user' => ['id' => 123]];

        yield [
            $context,
            function (Event $event) {
                $this->assertNotNull($event->getUser());
                $this->assertEquals(123, $event->getUser()->getId());
                $this->assertEmpty($event->getExtra());
            },
        ];

        $context = ['tags' => [
            'foo' => 'bar',
            'bar' => 123,
        ]];

        yield [
            $context,
            function (Event $event) {
                $this->assertSame([
                    'foo' => 'bar',
                    'bar' => '123',
                ], $event->getTags());
                $this->assertEmpty($event->getExtra());
            },
        ];
    }
}
