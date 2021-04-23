<?php

namespace Sentry\Laravel\Tests;

use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Route;
use Mockery;
use Sentry\Event;
use Sentry\Laravel\Integration;
use Sentry\State\Scope;
use function Sentry\withScope;

class IntegrationTest extends SentryLaravelTestCase
{
    public function testIntegrationIsRegistered(): void
    {
        $integration = $this->getHubFromContainer()->getIntegration(Integration::class);

        $this->assertInstanceOf(Integration::class, $integration);
    }

    public function testTransactionIsSetWhenRouteMatchedEventIsFired(): void
    {
        if (!class_exists(RouteMatched::class)) {
            $this->markTestSkipped('RouteMatched event class does not exist on this version of Laravel.');
        }

        Integration::setTransaction(null);

        $event = new RouteMatched(
            new Route('GET', $routeUrl = '/sentry-route-matched-event', static function () {
                // do nothing...
            }),
            Mockery::mock(Request::class)->makePartial()
        );

        $this->dispatchLaravelEvent($event);

        $this->assertSame($routeUrl, Integration::getTransaction());
    }

    public function testTransactionIsSetWhenRouterMatchedEventIsFired(): void
    {
        Integration::setTransaction(null);

        $this->dispatchLaravelEvent('router.matched', [
            new Route('GET', $routeUrl = '/sentry-router-matched-event', static function () {
                // do nothing...
            }),
        ]);

        $this->assertSame($routeUrl, Integration::getTransaction());
    }

    public function testTransactionIsAppliedToEventWithoutTransaction(): void
    {
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
