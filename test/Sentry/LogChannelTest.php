<?php

namespace Sentry\Laravel\Tests;

use Monolog\Handler\FingersCrossedHandler;
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
}
