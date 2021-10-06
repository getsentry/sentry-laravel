<?php

namespace Sentry\Laravel\Tests\Sentry\Laravel;

use Illuminate\Log\LogManager;
use Monolog\Handler\FingersCrossedHandler;
use Sentry\Laravel\LogChannel;
use Sentry\Laravel\SentryHandler;
use Sentry\Laravel\Tests\SentryLaravelTestCase;

class LogChannelTest extends SentryLaravelTestCase
{
    public function test_creating_handler_without_action_level_config()
    {
        $this->skipIfLogManagerNotAvailable();

        $logChannel = new LogChannel($this->app);
        $logger = $logChannel([]);

        $this->assertContainsOnlyInstancesOf(SentryHandler::class, $logger->getHandlers());
    }

    public function test_creating_handler_with_action_level_config()
    {
        $this->skipIfLogManagerNotAvailable();

        $logChannel = new LogChannel($this->app);
        $logger = $logChannel(['action_level' => 'critical']);

        $this->assertContainsOnlyInstancesOf(FingersCrossedHandler::class, $logger->getHandlers());

        $currentHandler = current($logger->getHandlers());
        $this->assertInstanceOf(SentryHandler::class, $currentHandler->getHandler());

        $loggerWithoutActionLevel = $logChannel(['action_level' => null]);

        $this->assertContainsOnlyInstancesOf(SentryHandler::class, $loggerWithoutActionLevel->getHandlers());
    }

    private function skipIfLogManagerNotAvailable()
    {
        if (class_exists(LogManager::class)) {
            return;
        }

        $this->markTestSkipped('Laravel version <=5.5 does not contain the LogManager required for this functionality.');
    }
}
