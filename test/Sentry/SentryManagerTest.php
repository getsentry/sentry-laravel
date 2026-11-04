<?php

namespace Sentry\Laravel\Tests;

use Exception;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\Laravel\Facade as SentryFacade;
use Sentry\Laravel\SentryManager;
use Sentry\NoOpClient;
use Sentry\SentrySdk;
use Sentry\State\IsolationScope;
use Sentry\Tracing\SpanContext;

class SentryManagerTest extends TestCase
{
    public function testContainerBindingsResolveTheSameInstance(): void
    {
        $sentry = $this->app->make(SentryManager::class);

        $this->assertSame($sentry, $this->app->make('sentry'));
        $this->assertSame($sentry, SentryFacade::getFacadeRoot());
    }

    public function testClientIsTheSdkClient(): void
    {
        $this->assertSame(SentrySdk::getClient(), $this->getSentryFromContainer()->getClient());
        $this->assertNotInstanceOf(NoOpClient::class, $this->getSentryFromContainer()->getClient());
    }

    public function testCaptureExceptionThroughFacade(): void
    {
        SentryFacade::captureException(new Exception('facade exception'));

        $this->assertSentryEventCount(1);
        $this->assertSame('facade exception', $this->getLastSentryEvent()->getExceptions()[0]->getValue());
    }

    public function testConfigureScopeMutatesCurrentIsolationScope(): void
    {
        SentryFacade::configureScope(static function (IsolationScope $scope): void {
            $scope->setTag('facade', 'yes');
        });

        $event = $this->applyCurrentSentryScopesToEvent(Event::createEvent());

        $this->assertSame('yes', $event->getTags()['facade'] ?? null);
    }

    public function testWithScopeForksCurrentIsolationScope(): void
    {
        SentryFacade::withScope(static function (IsolationScope $scope): void {
            $scope->setTag('forked', 'yes');

            SentryFacade::captureMessage('inside scope');
        });

        $this->assertSame('yes', $this->getLastSentryEvent()->getTags()['forked'] ?? null);

        $event = $this->applyCurrentSentryScopesToEvent(Event::createEvent());

        $this->assertArrayNotHasKey('forked', $event->getTags());
    }

    public function testAddBreadcrumb(): void
    {
        SentryFacade::addBreadcrumb(new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'test', 'facade breadcrumb'));

        $this->assertSame('facade breadcrumb', $this->getLastSentryBreadcrumb()->getMessage());
    }

    public function testSetSpanAndGetTransaction(): void
    {
        $transaction = $this->startTransaction();
        $span = $transaction->startChild(new SpanContext());

        SentryFacade::setSpan($span);

        $this->assertSame($span, SentryFacade::getSpan());
        $this->assertSame($transaction, SentryFacade::getTransaction());
        $this->assertSame($span, SentrySdk::getIsolationScope()->getSpan());
    }

    public function testScopeDataSetBeforeTheFirstClientIsKept(): void
    {
        SentrySdk::getGlobalScope()->setClient(new NoOpClient());
        SentrySdk::getIsolationScope()->setTag('early', 'yes');

        $this->refreshApplication();

        $event = $this->applyCurrentSentryScopesToEvent(Event::createEvent());

        $this->assertSame('yes', $event->getTags()['early'] ?? null);
    }

    public function testScopeDataIsResetWhenReplacingAClient(): void
    {
        SentrySdk::getIsolationScope()->setTag('previous_app', 'yes');

        $this->refreshApplication();

        $event = $this->applyCurrentSentryScopesToEvent(Event::createEvent());

        $this->assertArrayNotHasKey('previous_app', $event->getTags());
    }
}
