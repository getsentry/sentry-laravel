<?php

namespace Sentry\Laravel\Tests\Features;

use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\ApplicationBuilder;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Laravel\Integration;
use Sentry\Laravel\Tests\TestCase;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ConsoleExceptionScopePropagationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Exceptions::class) || !class_exists(ApplicationBuilder::class) || !method_exists(Application::class, 'configure')) {
            $this->markTestSkipped('Laravel 11+ exception configuration is required.');
        }

        parent::setUp();
    }

    protected function resolveApplication()
    {
        return (new ApplicationBuilder(new Application($this->getApplicationBasePath())))
            ->withProviders()
            ->withMiddleware(static function (Middleware $middleware): void {
                //
            })
            ->withCommands([
                ThrowConsoleExceptionCommand::class,
            ])
            ->withExceptions(function (Exceptions $exceptions): void {
                \Sentry\configureScope(static function (Scope $scope): void {
                    $scope->setContext('Context name', [
                        'key' => 'value',
                    ]);

                    $scope->setTag('tag_key', 'tag_value');
                });

                Integration::handles($exceptions);
            })
            ->create();
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        self::$lastSentryEvents = [];
        SentrySdk::init();

        tap($app['config'], function (Repository $config): void {
            // This key has no meaning, it's just a randomly generated one but it's required for the app to boot properly
            $config->set('app.key', 'base64:JfXL2QpYC1+szaw+CdT6SHXG8zjdTkKM/ctPWoTWbXU=');

            $config->set('sentry.before_send', static function (Event $event, ?EventHint $hint) {
                self::$lastSentryEvents[] = [$event, $hint];

                return null;
            });

            $config->set('sentry.dsn', 'https://publickey@sentry.dev/123');
        });

        // This simulates the scenario where Laravel first resolves the exception handler, which will execute the
        // withExceptions(..) callback and creates the real Sentry hub later.
        $app->make(ExceptionHandler::class);
    }

    public function testUnhandledConsoleExceptionKeepsScopeConfiguredThroughWithExceptions(): void
    {
        $exitCode = $this->runConsoleCommand('sentry:test-cli-scope-regression');

        $this->assertSame(1, $exitCode);
        $this->assertSentryEventCount(1);

        $event = $this->getLastSentryEvent();

        $this->assertNotNull($event);
        $this->assertSame('tag_value', $event->getTags()['tag_key'] ?? null);
        $this->assertSame('value', $event->getContexts()['Context name']['key'] ?? null);
    }

    public function testUnhandledConsoleExceptionIsReportedAfterCommandScopeCleanup(): void
    {
        $exitCode = $this->runConsoleCommand('sentry:test-cli-scope-regression');

        $this->assertSame(1, $exitCode);

        $event = $this->getLastSentryEvent();

        $this->assertNotNull($event);
        $this->assertArrayNotHasKey('command', $event->getTags());
    }

    private function runConsoleCommand(string $command): int
    {
        $input = new ArgvInput(['artisan', $command]);
        $output = new BufferedOutput();
        $kernel = $this->app->make(ConsoleKernelContract::class);

        try {
            $exitCode = $kernel->handle($input, $output);
        } catch (\Throwable $exception) {
            $this->app->make(ExceptionHandler::class)->report($exception);

            $exitCode = 1;
        }

        $kernel->terminate($input, $exitCode);

        return $exitCode;
    }
}

class ThrowConsoleExceptionCommand extends Command
{
    /** @var string */
    protected $signature = 'sentry:test-cli-scope-regression';

    /** @var string */
    protected $description = 'Throw an exception to test console scope propagation';

    public function handle()
    {
        throw new \RuntimeException('Unhandled console exception for scope propagation regression test.');
    }
}
