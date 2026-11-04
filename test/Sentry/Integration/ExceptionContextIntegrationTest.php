<?php

namespace Sentry\Laravel\Tests\Integration;

use Exception;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Laravel\Integration\ExceptionContextIntegration;
use Sentry\Laravel\Tests\TestCase;

class ExceptionContextIntegrationTest extends TestCase
{
    public function testExceptionContextIntegrationIsRegistered(): void
    {
        $integration = $this->getSentryFromContainer()->getIntegration(ExceptionContextIntegration::class);

        $this->assertInstanceOf(ExceptionContextIntegration::class, $integration);
    }

    /**
     * @dataProvider invokeDataProvider
     */
    public function testInvoke(Exception $exception, ?array $expectedContext): void
    {
        $event = Event::createEvent();

        $event = $this->applyCurrentSentryScopesToEvent($event, EventHint::fromArray(compact('exception')));

        $this->assertNotNull($event);

        $exceptionContext = $event->getExtra()['exception_context'] ?? null;

        $this->assertSame($expectedContext, $exceptionContext);
    }

    public static function invokeDataProvider(): iterable
    {
        yield 'Exception without context method -> no exception context' => [
            new Exception('Exception without context.'),
            null,
        ];

        $context = ['some' => 'context'];

        yield 'Exception with context method returning array of context' => [
            self::generateExceptionWithContext($context),
            $context,
        ];

        yield 'Exception with context method returning string of context' => [
            self::generateExceptionWithContext('Invalid context, expects array'),
            null,
        ];
    }

    private static function generateExceptionWithContext($context): Exception
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
