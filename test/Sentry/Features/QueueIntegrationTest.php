<?php

namespace Sentry\Laravel\Tests\Features;

use Exception;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventType;
use Sentry\Laravel\Tests\TestCase;
use Sentry\SentrySdk;
use Sentry\State\IsolationScope;
use function Sentry\addBreadcrumb;
use function Sentry\captureException;
use function Sentry\captureMessage;
use function Sentry\configureScope;

class QueueIntegrationTest extends TestCase
{
    public function testQueueJobPushesAndPopsScopeWithBreadcrumbs(): void
    {
        dispatch(new QueueEventsTestJobWithBreadcrumb);

        $this->assertCount(0, $this->getCurrentSentryBreadcrumbs());
    }

    public function testQueueJobThatReportsPushesAndPopsScopeWithBreadcrumbs(): void
    {
        dispatch(new QueueEventsTestJobThatReportsAnExceptionWithBreadcrumb);

        $this->assertCount(0, $this->getCurrentSentryBreadcrumbs());

        $this->assertNotNull($this->getLastSentryEvent());

        $event = $this->getLastSentryEvent();

        $this->assertCount(2, $event->getBreadcrumbs());
    }

    public function testQueueJobThatThrowsPopsPushedScope(): void
    {
        $parentScope = SentrySdk::getIsolationScope();

        try {
            dispatch(new QueueEventsTestJobThatThrowsAnUnhandledExceptionWithBreadcrumb);
        } catch (Exception $e) {
            // No action required, expected to throw
        }

        $this->assertSame($parentScope, SentrySdk::getIsolationScope());
        $this->assertCount(0, $this->getCurrentSentryBreadcrumbs());
    }

    public function testQueueJobsThatThrowDoNotLeaveScopesBehind(): void
    {
        $parentScope = SentrySdk::getIsolationScope();

        foreach (['test #1', 'test #2'] as $message) {
            try {
                dispatch(new QueueEventsTestJobThatThrowsAnUnhandledExceptionWithBreadcrumb($message));
            } catch (Exception $e) {
                // No action required, expected to throw
            }
        }

        $this->assertSame($parentScope, SentrySdk::getIsolationScope());
        $this->assertFalse(SentrySdk::popIsolationScope());
    }

    public function testReportedExceptionOfJobThatThrowsHasTheScopeOfTheJob(): void
    {
        configureScope(static function (IsolationScope $scope): void {
            $scope->setTag('parent', 'yes');
        });

        $parentScope = SentrySdk::getIsolationScope();

        $this->dispatchAndReportException(new QueueEventsTestJobThatSetsATagAndThrows);

        $event = $this->getLastSentryEvent();

        $this->assertSame(['parent' => 'yes', 'job' => 'yes'], $event->getTags());
        $this->assertCount(2, $event->getBreadcrumbs());
        $this->assertSame('queue.job', $event->getBreadcrumbs()[0]->getCategory());
        $this->assertSame('test', $event->getBreadcrumbs()[1]->getCategory());

        $this->assertSame($parentScope, SentrySdk::getIsolationScope());
        $this->assertSame(['parent' => 'yes'], $this->applyCurrentSentryScopesToEvent(Event::createEvent())->getTags());
    }

    public function testReportedExceptionOfJobDispatchedFromAJobHasTheScopeOfTheJobThatThrew(): void
    {
        $this->dispatchAndReportException(new QueueEventsTestJobThatDispatchesAJobThatThrows);

        $breadcrumbs = $this->getLastSentryEvent()->getBreadcrumbs();

        $this->assertCount(2, $breadcrumbs);
        $this->assertSame('queue.job', $breadcrumbs[0]->getCategory());
        $this->assertSame('inner job', $breadcrumbs[1]->getMessage());
        $this->assertFalse(SentrySdk::popIsolationScope());
    }

    public function testReportedExceptionThatDidNotFailAJobHasTheCurrentScope(): void
    {
        try {
            dispatch(new QueueEventsTestJobThatThrowsAnUnhandledExceptionWithBreadcrumb);
        } catch (Exception $e) {
            // No action required, expected to throw
        }

        $this->app->make(ExceptionHandler::class)->report(new Exception('Not a job exception'));

        $this->assertCount(0, $this->getLastSentryEvent()->getBreadcrumbs());
    }

    public function testQueueJobsWithBreadcrumbSetInBetweenKeepsNonJobBreadcrumbsOnCurrentScope(): void
    {
        dispatch(new QueueEventsTestJobWithBreadcrumb);

        addBreadcrumb(new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::LEVEL_DEBUG, 'test2', 'test2'));

        dispatch(new QueueEventsTestJobWithBreadcrumb);

        $this->assertCount(1, $this->getCurrentSentryBreadcrumbs());
    }

    public function testQueueJobScopeDataDoesNotLeakIntoTheParentScope(): void
    {
        configureScope(static function (IsolationScope $scope): void {
            $scope->setTag('parent', 'yes');
        });

        $parentScope = SentrySdk::getIsolationScope();

        dispatch(new QueueEventsTestJobThatSetsATagAndReports);

        $this->assertSame(['parent' => 'yes', 'job' => 'yes'], $this->getLastSentryEvent()->getTags());

        $this->assertSame($parentScope, SentrySdk::getIsolationScope());
        $this->assertSame(['parent' => 'yes'], $this->applyCurrentSentryScopesToEvent(Event::createEvent())->getTags());
    }

    private function dispatchAndReportException(ShouldQueue $job): void
    {
        try {
            dispatch($job);
        } catch (Exception $e) {
            $this->app->make(ExceptionHandler::class)->report($e);

            return;
        }

        $this->fail('Expected the job to throw.');
    }

    protected function withTracingEnabled($app): void
    {
        $app['config']->set('sentry.traces_sample_rate', 1.0);
    }

    /**
     * @define-env withTracingEnabled
     */
    #[DefineEnvironment('withTracingEnabled')]
    public function testQueueJobCreatesTransactionByDefault(): void
    {
        dispatch(new QueueEventsTestJob);

        $transaction = $this->getLastSentryEvent();

        $this->assertNotNull($transaction);

        $this->assertEquals(EventType::transaction(), $transaction->getType());
        $this->assertEquals(QueueEventsTestJob::class, $transaction->getTransaction());

        $traceContext = $transaction->getContexts()['trace'];

        $this->assertEquals('queue.process', $traceContext['op']);
    }

    protected function withQueueJobTracingDisabled($app): void
    {
        $app['config']->set('sentry.traces_sample_rate', 1.0);
        $app['config']->set('sentry.tracing.queue_job_transactions', false);
    }

    /**
     * @define-env withQueueJobTracingDisabled
     */
    #[DefineEnvironment('withQueueJobTracingDisabled')]
    public function testQueueJobDoesntCreateTransaction(): void
    {
        dispatch(new QueueEventsTestJob);

        $transaction = $this->getLastSentryEvent();

        $this->assertNull($transaction);
    }
}

class QueueEventsTestJob implements ShouldQueue
{
    public function handle(): void
    {
    }
}

function queueEventsTestAddTestBreadcrumb($message = null): void
{
    addBreadcrumb(
        new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::LEVEL_DEBUG,
            'test',
            $message ?? 'test'
        )
    );
}

class QueueEventsTestJobWithBreadcrumb implements ShouldQueue
{
    public function handle(): void
    {
        queueEventsTestAddTestBreadcrumb();
    }
}

class QueueEventsTestJobThatReportsAnExceptionWithBreadcrumb implements ShouldQueue
{
    public function handle(): void
    {
        queueEventsTestAddTestBreadcrumb();

        captureException(new Exception('This is a test exception'));
    }
}

class QueueEventsTestJobThatThrowsAnUnhandledExceptionWithBreadcrumb implements ShouldQueue
{
    private $message;

    public function __construct($message = null)
    {
        $this->message = $message;
    }

    public function handle(): void
    {
        queueEventsTestAddTestBreadcrumb($this->message);

        throw new Exception('This is a test exception');
    }
}

class QueueEventsTestJobThatSetsATagAndReports implements ShouldQueue
{
    public function handle(): void
    {
        configureScope(static function (IsolationScope $scope): void {
            $scope->setTag('job', 'yes');
        });

        captureMessage('job message');
    }
}

class QueueEventsTestJobThatSetsATagAndThrows implements ShouldQueue
{
    public function handle(): void
    {
        configureScope(static function (IsolationScope $scope): void {
            $scope->setTag('job', 'yes');
        });

        queueEventsTestAddTestBreadcrumb();

        throw new Exception('This is a test exception');
    }
}

class QueueEventsTestJobThatDispatchesAJobThatThrows implements ShouldQueue
{
    public function handle(): void
    {
        queueEventsTestAddTestBreadcrumb('outer job');

        dispatch(new QueueEventsTestJobThatThrowsAnUnhandledExceptionWithBreadcrumb('inner job'));
    }
}
