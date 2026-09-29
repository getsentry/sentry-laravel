<?php

namespace Sentry\Features;

use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Sentry\Laravel\Tests\TestCase;
use Sentry\EventType;
use Sentry\Logs\LogLevel;
use function Sentry\logger;

class LogLogsIntegrationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        tap($app['config'], static function (Repository $config) {
            $config->set('sentry.enable_logs', true);

            $config->set('logging.channels.sentry_logs', [
                'driver' => 'sentry_logs',
            ]);

            $config->set('logging.channels.sentry_logs_error_level', [
                'driver' => 'sentry_logs',
                'level' => 'error',
            ]);
        });
    }

    public function testLogChannelIsRegistered(): void
    {
        $this->expectNotToPerformAssertions();

        Log::channel('sentry_logs');
    }

    /** @define-env envWithoutDsnSet */
    #[DefineEnvironment('envWithoutDsnSet')]
    public function testLogChannelIsRegisteredWithoutDsn(): void
    {
        $this->expectNotToPerformAssertions();

        Log::channel('sentry_logs');
    }

    public function testLogChannelGeneratesLogs(): void
    {
        $logger = Log::channel('sentry_logs');

        $logger->info('Sentry Laravel info log message');

        $logs = $this->getAndFlushCapturedLogs();

        $this->assertCount(1, $logs);

        $log = $logs[0];

        $this->assertEquals(LogLevel::info(), $log->getLevel());
        $this->assertEquals('Sentry Laravel info log message', $log->getBody());
    }

    public function testLogChannelGeneratesLogsOnlyForConfiguredLevel(): void
    {
        $logger = Log::channel('sentry_logs_error_level');

        $logger->info('Sentry Laravel info log message');
        $logger->warning('Sentry Laravel warning log message');
        $logger->error('Sentry Laravel error log message');

        $logs = $this->getAndFlushCapturedLogs();

        $this->assertCount(1, $logs);

        $log = $logs[0];

        $this->assertEquals(LogLevel::error(), $log->getLevel());
        $this->assertEquals('Sentry Laravel error log message', $log->getBody());
    }

    public function testLogChannelCapturesExceptions(): void
    {
        $logger = Log::channel('sentry_logs');

        $logger->error('Sentry Laravel error log message', ['exception' => new \Exception('Test exception')]);

        $logs = $this->getAndFlushCapturedLogs();

        $this->assertCount(1, $logs);

        $log = $logs[0];

        $this->assertEquals(LogLevel::error(), $log->getLevel());
        $this->assertEquals('Sentry Laravel error log message', $log->getBody());
        $this->assertNull($log->attributes()->get('exception'));
    }

    public function testLogChannelFlushesImmediatelyWhenThresholdIsReached(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.log_flush_threshold' => 2,
        ]);

        $logger = Log::channel('sentry_logs');

        $logger->warning('Sentry Laravel warning log message');
        $logger->error('Sentry Laravel error log message');

        $this->assertCount(0, logger()->aggregator()->all());

        $logEvents = array_values(array_filter($this->getCapturedSentryEvents(), static function (array $event): bool {
            return $event[0]->getType() === EventType::logs();
        }));

        $this->assertCount(1, $logEvents);
        $this->assertCount(2, $logEvents[0][0]->getLogs());
        $this->assertEquals('Sentry Laravel warning log message', $logEvents[0][0]->getLogs()[0]->getBody());
        $this->assertEquals('Sentry Laravel error log message', $logEvents[0][0]->getLogs()[1]->getBody());
    }

    public function testLogChannelDoesNotFlushImmediatelyWhenThresholdIsNull(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.log_flush_threshold' => null,
        ]);

        $logger = Log::channel('sentry_logs');

        $logger->warning('Sentry Laravel warning log message');
        $logger->error('Sentry Laravel error log message');

        $bufferedLogs = logger()->aggregator()->all();

        $this->assertCount(2, $bufferedLogs);

        $logEvents = array_values(array_filter($this->getCapturedSentryEvents(), static function (array $event): bool {
            return $event[0]->getType() === EventType::logs();
        }));

        $this->assertCount(0, $logEvents);

        logger()->aggregator()->flush();
    }

    public function testLogChannelAddsContextAsAttributes(): void
    {
        $logger = Log::channel('sentry_logs');

        $logger->info('Sentry Laravel info log message', [
            'foo' => 'bar',
        ]);

        $logs = $this->getAndFlushCapturedLogs();

        $this->assertCount(1, $logs);

        $log = $logs[0];

        $this->assertEquals('bar', $log->attributes()->get('foo')->getValue());
    }

    /** @return \Sentry\Logs\Log[] */
    private function getAndFlushCapturedLogs(): array
    {
        $logs = logger()->aggregator()->all();

        logger()->aggregator()->flush();

        return $logs;
    }
}
