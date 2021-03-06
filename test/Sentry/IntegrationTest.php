<?php

namespace Sentry\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\Laravel\Integration;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use function Sentry\withScope;

class IntegrationTest extends TestCase
{
    private static $integration;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$integration = new Integration;
        self::$integration->setupOnce();
    }

    public function testTransactionIsAppliedToEventWithoutTransaction(): void
    {
        /** @var ClientInterface&MockObject $client */
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())
            ->method('getIntegration')
            ->willReturn(self::$integration);

        SentrySdk::getCurrentHub()->bindClient($client);

        Integration::setTransaction($transaction = 'some-transaction-name');

        withScope(function (Scope $scope) use ($transaction): void {
            $event = Event::createEvent();

            $this->assertNull($event->getTransaction());

            $event = $scope->applyToEvent($event);

            $this->assertNotNull($event);

            $this->assertSame($transaction, $event->getTransaction());
        });
    }

    public function testTransactionIsAppliedToEventWithEmptyTransaction(): void
    {
        /** @var ClientInterface&MockObject $client */
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())
            ->method('getIntegration')
            ->willReturn(self::$integration);

        SentrySdk::getCurrentHub()->bindClient($client);

        Integration::setTransaction($transaction = 'some-transaction-name');

        withScope(function (Scope $scope) use ($transaction): void {
            $event = Event::createEvent();
            $event->setTransaction($emptyTransaction = '');

            $this->assertSame($emptyTransaction, $event->getTransaction());

            $event = $scope->applyToEvent($event);

            $this->assertNotNull($event);

            $this->assertSame($transaction, $event->getTransaction());
        });
    }

    public function testTransactionIsNotAppliedToEventWhenTransactionIsAlreadySet(): void
    {
        /** @var ClientInterface&MockObject $client */
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())
            ->method('getIntegration')
            ->willReturn(self::$integration);

        SentrySdk::getCurrentHub()->bindClient($client);

        Integration::setTransaction('some-transaction-name');

        withScope(function (Scope $scope): void {
            $event = Event::createEvent();

            $event->setTransaction($eventTransaction = 'some-other-transaction-name');

            $this->assertSame($eventTransaction, $event->getTransaction());

            $event = $scope->applyToEvent($event);

            $this->assertNotNull($event);

            $this->assertSame($eventTransaction, $event->getTransaction());
        });
    }
}
