<?php

namespace Sentry\Laravel\Tests\Features;

use Exception;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Queue\SyncQueue;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventType;
use Sentry\Laravel\Integration;
use Sentry\Laravel\Tests\TestCase;
use Sentry\SentrySdk;
use Sentry\State\IsolationScope;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Throwable;
use function Sentry\addBreadcrumb;
use function Sentry\captureException;
use function Sentry\captureMessage;
use function Sentry\configureScope;

class QueueIntegrationTest extends TestCase
{
    /** @var QueueEventsTestWorkerQueue|null */
    private $workerQueue;

    public function testSyncJobRunsInTheScopeOfTheDispatcher(): void
    {
        $scope = SentrySdk::getIsolationScope();

        dispatch(new QueueEventsTestJobWithBreadcrumb);

        $this->assertSame($scope, SentrySdk::getIsolationScope());
        $this->assertCount(2, $this->getCurrentSentryBreadcrumbs());
    }

    public function testSyncJobThatReportsHasTheBreadcrumbsOfTheDispatcherAndTheJob(): void
    {
        queueEventsTestAddTestBreadcrumb('before the job');

        dispatch(new QueueEventsTestJobThatReportsAnExceptionWithBreadcrumb);

        $event = $this->getLastSentryEvent();

        $this->assertNotNull($event);
        $this->assertSame(
            ['before the job', 'Processing queue job', 'test'],
            $this->getBreadcrumbMessages($event->getBreadcrumbs())
        );
    }

    public function testSyncJobsAddTheirBreadcrumbsToTheScopeOfTheDispatcher(): void
    {
        dispatch(new QueueEventsTestJobWithBreadcrumb);

        queueEventsTestAddTestBreadcrumb('in between');

        dispatch(new QueueEventsTestJobWithBreadcrumb);

        $this->assertSame(
            ['Processing queue job', 'test', 'in between', 'Processing queue job', 'test'],
            $this->getBreadcrumbMessages($this->getCurrentSentryBreadcrumbs())
        );
    }

    public function testDataSetBySyncJobStaysOnTheScopeOfTheDispatcher(): void
    {
        configureScope(static function (IsolationScope $scope): void {
            $scope->setTag('parent', 'yes');
        });

        $scope = SentrySdk::getIsolationScope();

        dispatch(new QueueEventsTestJobThatSetsATagAndReports);

        $this->assertSame(['parent' => 'yes', 'job' => 'yes'], $this->getLastSentryEvent()->getTags());

        $this->assertSame($scope, SentrySdk::getIsolationScope());
        $this->assertSame(['parent' => 'yes', 'job' => 'yes'], $this->applyCurrentSentryScopesToEvent(Event::createEvent())->getTags());
    }

    public function testSyncJobThatThrowsLeavesTheScopeOfTheDispatcherInPlace(): void
    {
        $scope = SentrySdk::getIsolationScope();

        try {
            dispatch(new QueueEventsTestJobThatThrowsAnUnhandledExceptionWithBreadcrumb);
        } catch (Exception $e) {
            // No action required, expected to throw
        }

        $this->assertSame($scope, SentrySdk::getIsolationScope());
        $this->assertCount(2, $this->getCurrentSentryBreadcrumbs());
        $this->assertFalse(SentrySdk::popIsolationScope());
    }

    public function testSyncJobsThatThrowDoNotLeaveScopesBehind(): void
    {
        $scope = SentrySdk::getIsolationScope();

        foreach (['test #1', 'test #2'] as $message) {
            try {
                dispatch(new QueueEventsTestJobThatThrowsAnUnhandledExceptionWithBreadcrumb($message));
            } catch (Exception $e) {
                // No action required, expected to throw
            }
        }

        $this->assertSame($scope, SentrySdk::getIsolationScope());
        $this->assertFalse(SentrySdk::popIsolationScope());
    }

    public function testReportedExceptionOfSyncJobThatThrowsHasTheDataOfTheJob(): void
    {
        configureScope(static function (IsolationScope $scope): void {
            $scope->setTag('parent', 'yes');
        });

        $this->dispatchAndReportException(new QueueEventsTestJobThatSetsATagAndThrows);

        $event = $this->getLastSentryEvent();

        $this->assertSame(['parent' => 'yes', 'job' => 'yes'], $event->getTags());
        $this->assertSame(['Processing queue job', 'test'], $this->getBreadcrumbMessages($event->getBreadcrumbs()));
    }

    public function testReportedExceptionOfSyncJobDispatchedFromASyncJobHasTheBreadcrumbsOfBothJobs(): void
    {
        $this->dispatchAndReportException(new QueueEventsTestJobThatDispatchesAJobThatThrows);

        $this->assertSame(
            ['Processing queue job', 'outer job', 'Processing queue job', 'inner job'],
            $this->getBreadcrumbMessages($this->getLastSentryEvent()->getBreadcrumbs())
        );
        $this->assertFalse(SentrySdk::popIsolationScope());
    }

    public function testWorkerJobPopsItsScopeWhenProcessed(): void
    {
        configureScope(static function (IsolationScope $scope): void {
            $scope->setTag('parent', 'yes');
        });

        $scope = SentrySdk::getIsolationScope();

        $this->runWorkerJob(new QueueEventsTestJobThatSetsATagAndReports);

        $this->assertSame(['parent' => 'yes', 'job' => 'yes'], $this->getLastSentryEvent()->getTags());

        $this->assertSame($scope, SentrySdk::getIsolationScope());
        $this->assertCount(0, $this->getCurrentSentryBreadcrumbs());
        $this->assertSame(['parent' => 'yes'], $this->applyCurrentSentryScopesToEvent(Event::createEvent())->getTags());
        $this->assertFalse(SentrySdk::popIsolationScope());
    }

    /**
     * @dataProvider exceptionReportingDataProvider
     */
    public function testExceptionOfWorkerJobIsReportedWithTheScopeOfTheJob(?callable $report): void
    {
        configureScope(static function (IsolationScope $scope): void {
            $scope->setTag('parent', 'yes');
        });

        $scope = SentrySdk::getIsolationScope();

        $this->runWorkerJob(new QueueEventsTestJobThatSetsATagAndThrows, $report);

        $event = $this->getLastSentryEvent();

        $this->assertNotNull($event);
        $this->assertSame(['parent' => 'yes', 'job' => 'yes'], $event->getTags());
        $this->assertSame(['Processing queue job', 'test'], $this->getBreadcrumbMessages($event->getBreadcrumbs()));

        // The scope of the job is kept until the worker continues with the next loop iteration
        $this->assertNotSame($scope, SentrySdk::getIsolationScope());

        $this->app['events']->dispatch(new Looping('worker', 'default'));

        $this->assertSame($scope, SentrySdk::getIsolationScope());
        $this->assertCount(0, $this->getCurrentSentryBreadcrumbs());
        $this->assertFalse(SentrySdk::popIsolationScope());
    }

    public static function exceptionReportingDataProvider(): iterable
    {
        yield 'exception handler of the app' => [null];

        yield 'Integration::captureUnhandledException()' => [static function (Throwable $exception): void {
            Integration::captureUnhandledException($exception);
        }];

        yield 'app(\'sentry\')->captureException()' => [static function (Throwable $exception): void {
            app('sentry')->captureException($exception);
        }];

        yield '\Sentry\captureException()' => [static function (Throwable $exception): void {
            captureException($exception);
        }];
    }

    public function testScopeOfFailedWorkerJobIsKeptWhenASyncJobRunsWhileTheExceptionIsReported(): void
    {
        configureScope(static function (IsolationScope $scope): void {
            $scope->setTag('parent', 'yes');
        });

        $scope = SentrySdk::getIsolationScope();

        $this->runWorkerJob(new QueueEventsTestJobThatSetsATagAndThrows, static function (Throwable $exception): void {
            dispatch(new QueueEventsTestJob);

            captureException($exception);
        });

        $this->assertSame(['parent' => 'yes', 'job' => 'yes'], $this->getLastSentryEvent()->getTags());

        $this->app['events']->dispatch(new Looping('worker', 'default'));

        $this->assertSame($scope, SentrySdk::getIsolationScope());
        $this->assertFalse(SentrySdk::popIsolationScope());
    }

    public function testScopeOfFailedWorkerJobIsKeptWhenACommandRunsWhileTheExceptionIsReported(): void
    {
        configureScope(static function (IsolationScope $scope): void {
            $scope->setTag('parent', 'yes');
        });

        $scope = SentrySdk::getIsolationScope();

        $this->runWorkerJob(new QueueEventsTestJobThatSetsATagAndThrows, function (Throwable $exception): void {
            // Laravel dispatches these events for a command called with `Artisan::call()` outside of unit tests
            $this->app['events']->dispatch(new CommandStarting('inspire', new ArrayInput([]), new NullOutput));
            $this->app['events']->dispatch(new CommandFinished('inspire', new ArrayInput([]), new NullOutput, 0));

            captureException($exception);
        });

        $this->assertSame(['parent' => 'yes', 'job' => 'yes'], $this->getLastSentryEvent()->getTags());

        $this->app['events']->dispatch(new CommandFinished('queue:work', new ArrayInput([]), new NullOutput, 0));

        $this->assertSame($scope, SentrySdk::getIsolationScope());
        $this->assertFalse(SentrySdk::popIsolationScope());
    }

    public function testScopeOfFailedWorkerJobIsPoppedWhenTheWorkerStops(): void
    {
        $scope = SentrySdk::getIsolationScope();

        $this->runWorkerJob(new QueueEventsTestJobThatThrowsAnUnhandledExceptionWithBreadcrumb);

        $this->assertNotSame($scope, SentrySdk::getIsolationScope());

        $this->app['events']->dispatch(new WorkerStopping);

        $this->assertSame($scope, SentrySdk::getIsolationScope());
        $this->assertFalse(SentrySdk::popIsolationScope());
    }

    public function testScopeOfFailedWorkerJobIsPoppedWhenTheCommandFinishes(): void
    {
        $scope = SentrySdk::getIsolationScope();

        $this->runWorkerJob(new QueueEventsTestJobThatThrowsAnUnhandledExceptionWithBreadcrumb);

        $this->assertNotSame($scope, SentrySdk::getIsolationScope());

        $this->app['events']->dispatch(new CommandFinished('queue:work', new ArrayInput([]), new NullOutput, 0));

        $this->assertSame($scope, SentrySdk::getIsolationScope());
        $this->assertFalse(SentrySdk::popIsolationScope());
    }

    public function testScopeOfFailedWorkerJobIsPoppedBeforeTheNextJobStarts(): void
    {
        configureScope(static function (IsolationScope $scope): void {
            $scope->setTag('parent', 'yes');
        });

        $scope = SentrySdk::getIsolationScope();

        $this->runWorkerJob(new QueueEventsTestJobThatSetsATagAndThrows);
        $this->runWorkerJob(new QueueEventsTestJobThatReportsAnExceptionWithBreadcrumb);

        $event = $this->getLastSentryEvent();

        // The second job got a scope of its own instead of the scope of the job that failed
        $this->assertSame(['parent' => 'yes'], $event->getTags());
        $this->assertSame(['Processing queue job', 'test'], $this->getBreadcrumbMessages($event->getBreadcrumbs()));

        $this->assertSame($scope, SentrySdk::getIsolationScope());
        $this->assertFalse(SentrySdk::popIsolationScope());
    }

    public function testReportedExceptionThatDidNotFailAWorkerJobHasTheCurrentScope(): void
    {
        $this->runWorkerJob(new QueueEventsTestJobThatThrowsAnUnhandledExceptionWithBreadcrumb);

        $this->app['events']->dispatch(new Looping('worker', 'default'));

        $this->app->make(ExceptionHandler::class)->report(new Exception('Not a job exception'));

        $this->assertCount(0, $this->getLastSentryEvent()->getBreadcrumbs());
    }

    public function testSyncJobDispatchedFromAWorkerJobRunsInTheScopeOfTheWorkerJob(): void
    {
        $scope = SentrySdk::getIsolationScope();

        $this->runWorkerJob(new QueueEventsTestJobThatDispatchesAJobAndReports);

        $event = $this->getLastSentryEvent();

        $this->assertSame(['worker' => 'yes'], $event->getTags());
        $this->assertSame(['Processing queue job', 'Processing queue job', 'test'], $this->getBreadcrumbMessages($event->getBreadcrumbs()));

        $this->assertSame($scope, SentrySdk::getIsolationScope());
        $this->assertCount(0, $this->getCurrentSentryBreadcrumbs());
        $this->assertFalse(SentrySdk::popIsolationScope());
    }

    public function testReportedExceptionOfSyncJobDispatchedFromAWorkerJobHasTheBreadcrumbsOfBothJobs(): void
    {
        $scope = SentrySdk::getIsolationScope();

        $this->runWorkerJob(new QueueEventsTestJobThatDispatchesAJobThatThrows);

        $this->assertSame(
            ['Processing queue job', 'outer job', 'Processing queue job', 'inner job'],
            $this->getBreadcrumbMessages($this->getLastSentryEvent()->getBreadcrumbs())
        );

        $this->app['events']->dispatch(new Looping('worker', 'default'));

        $this->assertSame($scope, SentrySdk::getIsolationScope());
        $this->assertFalse(SentrySdk::popIsolationScope());
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

    /**
     * Runs the job the way a queue worker does, which includes reporting the exception of a job that throws.
     *
     * @param callable|null $report Reports the exception of the job instead of the exception handler of the app
     */
    private function runWorkerJob(ShouldQueue $job, ?callable $report = null): void
    {
        if ($this->workerQueue === null) {
            $this->workerQueue = new QueueEventsTestWorkerQueue;

            $workerQueue = $this->workerQueue;

            $this->app['queue']->extend('worker', static function () use ($workerQueue): ConnectorInterface {
                return new QueueEventsTestWorkerConnector($workerQueue);
            });

            $this->app['config']->set('queue.connections.worker', ['driver' => 'worker']);
        }

        $this->workerQueue->jobs[] = $job;

        $exceptionHandler = $report === null
            ? $this->app->make(ExceptionHandler::class)
            : new QueueEventsTestExceptionHandler($report);

        $worker = new Worker($this->app['queue'], $this->app['events'], $exceptionHandler, static function (): bool {
            return false;
        });

        // WorkCommand sets the name too, avoiding a null array key in older workers on PHP 8.5.
        if (method_exists($worker, 'setName')) {
            $worker->setName('default');
        }

        $worker->runNextJob('worker', 'default', new WorkerOptions);
    }

    /**
     * @param Breadcrumb[] $breadcrumbs
     *
     * @return string[]
     */
    private function getBreadcrumbMessages(array $breadcrumbs): array
    {
        return array_map(static function (Breadcrumb $breadcrumb): ?string {
            return $breadcrumb->getMessage();
        }, $breadcrumbs);
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

class QueueEventsTestJobThatDispatchesAJobAndReports implements ShouldQueue
{
    public function handle(): void
    {
        configureScope(static function (IsolationScope $scope): void {
            $scope->setTag('worker', 'yes');
        });

        dispatch(new QueueEventsTestJobWithBreadcrumb);

        captureMessage('worker message');
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

/**
 * A queue connection a worker can pop jobs from, which the `sync` driver does not support.
 */
class QueueEventsTestWorkerQueue extends SyncQueue
{
    /** @var ShouldQueue[] */
    public $jobs = [];

    public function pop($queue = null)
    {
        $job = array_shift($this->jobs);

        if ($job === null) {
            return null;
        }

        return new QueueEventsTestWorkerJob($this->container, $this->createPayload($job, $queue), $this->connectionName, $queue);
    }
}

class QueueEventsTestWorkerConnector implements ConnectorInterface
{
    /** @var QueueEventsTestWorkerQueue */
    private $queue;

    public function __construct(QueueEventsTestWorkerQueue $queue)
    {
        $this->queue = $queue;
    }

    public function connect(array $config)
    {
        return $this->queue;
    }
}

class QueueEventsTestWorkerJob extends Job implements JobContract
{
    /** @var string */
    private $payload;

    public function __construct(Container $container, string $payload, string $connectionName, ?string $queue)
    {
        $this->container = $container;
        $this->payload = $payload;
        $this->connectionName = $connectionName;
        $this->queue = $queue;
    }

    public function getJobId()
    {
        return 'test';
    }

    public function getRawBody()
    {
        return $this->payload;
    }

    public function attempts()
    {
        return 1;
    }
}

class QueueEventsTestExceptionHandler implements ExceptionHandler
{
    /** @var callable */
    private $report;

    public function __construct(callable $report)
    {
        $this->report = $report;
    }

    public function report($e)
    {
        ($this->report)($e);
    }

    public function shouldReport($e)
    {
        return true;
    }

    public function render($request, $e)
    {
        throw $e;
    }

    public function renderForConsole($output, $e)
    {
        throw $e;
    }
}
