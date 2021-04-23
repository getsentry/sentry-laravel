<?php

namespace Sentry\Laravel\Tests\Integration;

use Exception;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Laravel\Integration\ExceptionContextIntegration;
use Sentry\Laravel\Tests\SentryLaravelTestCase;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use function Sentry\withScope;

class ExceptionContextIntegrationTest extends SentryLaravelTestCase
{
    public function testExceptionContextIntegrationIsRegistered(): void
    {
        $integration = $this->getHubFromContainer()->getIntegration(ExceptionContextIntegration::class);

        $this->assertInstanceOf(ExceptionContextIntegration::class, $integration);
    }

    /**
     * @dataProvider invokeDataProvider
     */
    public function testInvoke(Exception $exception, ?array $expectedContext): void
    {
        withScope(function (Scope $scope) use ($exception, $expectedContext): void {
            $event = Event::createEvent();

            $event = $scope->applyToEvent($event, EventHint::fromArray(compact('exception')));

            $this->assertNotNull($event);

            $exceptionContext = $event->getExtra()['exception_context'] ?? null;

            $this->assertSame($expectedContext, $exceptionContext);
        });
    }

    public function invokeDataProvider(): iterable
    {
        yield 'Exception without context method -> no exception context' => [
            new Exception('Exception without context.'),
            null,
        ];

        $context = ['some' => 'context'];

        yield 'Exception with context method returning array of context' => [
            $this->generateExceptionWithContext($context),
            $context,
        ];

        yield 'Exception with context method returning string of context' => [
            $this->generateExceptionWithContext('Invalid context, expects array'),
            null,
        ];
    }

    private function generateExceptionWithContext($context)
    {
        return new class($context) extends Exception {
            private $context;

            public function __construct($context)
            {
                $this->context = $context;

                parent::__construct('Exception with context.');
            }

            public function context()
            {
                return $this->context;
            }
        };
    }
}
