<?php

namespace Sentry\Laravel\Tests\Features;

use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Sentry\EventType;
use Sentry\Laravel\Tests\TestCase;
use function Sentry\captureException;

class QueueJobContextTest extends TestCase
{
    public function testNoJobContextIsAttachedWhenAllFeaturesAreDisabled(): void
    {
        dispatch(new QueueJobContextTestJobThatReports);

        $event = $this->getLastSentryEvent();

        $this->assertNotNull($event);
        $this->assertArrayNotHasKey('laravel.job', $event->getContexts());
        $this->assertArrayNotHasKey('queue', $event->getTags());
        $this->assertArrayNotHasKey('queue.connection', $event->getTags());
        $this->assertArrayNotHasKey('job', $event->getTags());
        $this->assertArrayNotHasKey('attempts', $event->getTags());
    }

    public function testQueueNameFeatureAttachesTagsAndContext(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.job_context.queue_name' => true,
        ]);

        dispatch(new QueueJobContextTestJobThatReports);

        $event = $this->getLastSentryEvent();

        $this->assertSame('sync', $event->getTags()['queue.connection']);
        $this->assertSame(QueueJobContextTestJobThatReports::class, $event->getTags()['job']);
        $this->assertArrayHasKey('queue', $event->getTags());

        $context = $event->getContexts()['laravel.job'];

        $this->assertSame('sync', $context['connection']);
        $this->assertArrayHasKey('queue', $context);
    }

    public function testQueueNameFeatureIsNotAttachedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.job_context.queue_name' => false,
            'sentry.job_context.attempts' => true,
        ]);

        dispatch(new QueueJobContextTestJobThatReports);

        $event = $this->getLastSentryEvent();

        $this->assertArrayNotHasKey('queue', $event->getTags());
        $this->assertArrayNotHasKey('queue', $event->getContexts()['laravel.job']);
    }

    public function testAttemptsFeatureAttachesTagAndContext(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.job_context.attempts' => true,
        ]);

        dispatch(new QueueJobContextTestJobThatReports);

        $event = $this->getLastSentryEvent();

        $this->assertSame('1', $event->getTags()['attempts']);
        $this->assertSame(1, $event->getContexts()['laravel.job']['attempts']);
    }

    public function testMemoryUsageFeatureAttachesContext(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.job_context.memory_usage' => true,
        ]);

        dispatch(new QueueJobContextTestJobThatReports);

        $memory = $this->getLastSentryEvent()->getContexts()['laravel.job']['memory'];

        $this->assertIsInt($memory['start']);
        $this->assertIsInt($memory['end']);
        $this->assertIsInt($memory['peak']);
        $this->assertNotEmpty($memory['limit']);
        $this->assertGreaterThanOrEqual($memory['start'], $memory['peak']);
    }

    public function testMemoryUsageFeatureIsNotAttachedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.job_context.attempts' => true,
        ]);

        dispatch(new QueueJobContextTestJobThatReports);

        $context = $this->getLastSentryEvent()->getContexts()['laravel.job'];

        $this->assertArrayNotHasKey('memory', $context);
    }

    public function testExecutionTimeFeatureAttachesContext(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.job_context.execution_time' => true,
        ]);

        dispatch(new QueueJobContextTestJobThatReports);

        $context = $this->getLastSentryEvent()->getContexts()['laravel.job'];

        $this->assertArrayHasKey('execution_time_ms', $context);
        $this->assertIsFloat($context['execution_time_ms']);
        $this->assertGreaterThanOrEqual(0, $context['execution_time_ms']);
    }

    public function testDatabaseFeatureRecordsConnectionUsedDuringJob(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.job_context.database' => true,
        ]);

        dispatch(new QueueJobContextTestJobThatQueriesDatabaseAndReports);

        $database = $this->getLastSentryEvent()->getContexts()['laravel.job']['database'];

        $this->assertSame(config('database.default'), $database['default']);
        $this->assertContains(config('database.default'), $database['connections_used']);
    }

    public function testDatabaseFeatureRecordsNoConnectionsWhenNoQueriesRan(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.job_context.database' => true,
        ]);

        dispatch(new QueueJobContextTestJobThatReports);

        $database = $this->getLastSentryEvent()->getContexts()['laravel.job']['database'];

        $this->assertSame([], $database['connections_used']);
    }

    public function testHorizonFeatureIsSkippedWhenPayloadHasNoHorizonKeys(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.job_context.horizon' => true,
        ]);

        dispatch(new QueueJobContextTestJobThatReports);

        $context = $this->getLastSentryEvent()->getContexts()['laravel.job'] ?? [];

        $this->assertArrayNotHasKey('horizon', $context);
    }

    public function testHorizonFeatureAttachesTagAndContextWhenPayloadHasHorizonKeys(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.job_context.horizon' => true,
        ]);

        // Simulate the extra payload keys `Laravel\Horizon\JobPayload::prepare()` adds,
        // without requiring the `laravel/horizon` package to be installed.
        Queue::createPayloadUsing(static function ($connection, $queue, $payload) {
            $payload['type'] = 'job';
            $payload['tags'] = ['App\\Models\\User:1'];
            $payload['silenced'] = false;
            $payload['pushedAt'] = microtime(true);

            return $payload;
        });

        $_SERVER['argv'][] = '--supervisor=supervisor-1';

        try {
            dispatch(new QueueJobContextTestJobThatReports);
        } finally {
            Queue::createPayloadUsing(null);
            array_pop($_SERVER['argv']);
        }

        $event = $this->getLastSentryEvent();

        $this->assertSame('supervisor-1', $event->getTags()['horizon.supervisor']);

        $horizon = $event->getContexts()['laravel.job']['horizon'];

        $this->assertSame('job', $horizon['type']);
        $this->assertSame(['App\\Models\\User:1'], $horizon['tags']);
        $this->assertFalse($horizon['silenced']);
        $this->assertSame('supervisor-1', $horizon['supervisor']);
    }

    public function testFailedSyncJobAttachesAllEnabledMeasurements(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.job_context.queue_name' => true,
            'sentry.job_context.attempts' => true,
            'sentry.job_context.memory_usage' => true,
            'sentry.job_context.execution_time' => true,
        ]);

        try {
            dispatch(new QueueJobContextTestJobThatThrows);
        } catch (Exception $e) {
            // The scope pushed for the job is only popped by the next `JobProcessing` event,
            // so reporting here (as Laravel's queue worker does for a failed job) still runs
            // within the job's scope and event processor.
            report($e);
        }

        $event = $this->getLastSentryEvent();

        $this->assertNotNull($event);
        $this->assertSame('sync', $event->getTags()['queue.connection']);
        $this->assertSame('1', $event->getTags()['attempts']);

        $context = $event->getContexts()['laravel.job'];

        $this->assertArrayHasKey('memory', $context);
        $this->assertArrayHasKey('execution_time_ms', $context);
        $this->assertSame(1, $context['attempts']);
    }

    public function testQueueIntegrationStillRecordsBreadcrumbsWhenQueueInfoEnabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.queue_info' => true,
            'sentry.job_context.queue_name' => true,
        ]);

        dispatch(new QueueJobContextTestJobThatReports);

        $event = $this->getLastSentryEvent();

        $this->assertNotEmpty($event->getBreadcrumbs());
        $this->assertSame('queue.job', $event->getBreadcrumbs()[0]->getCategory());
    }

    public function testJobContextIsMergedOntoQueueProcessSpanWhenTracingEnabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 1.0,
            'sentry.job_context.memory_usage' => true,
            'sentry.job_context.execution_time' => true,
        ]);

        dispatch(new QueueJobContextTestJob);

        $transaction = $this->getLastSentryEvent();

        $this->assertNotNull($transaction);

        $traceContext = $transaction->getContexts()['trace'];

        $this->assertSame('queue.process', $traceContext['op']);
        $this->assertArrayHasKey('job_context.execution_time_ms', $traceContext['data']);
        $this->assertArrayHasKey('job_context.memory.start', $traceContext['data']);

        // Existing `messaging.*` span data must be preserved alongside our own keys.
        $this->assertArrayHasKey('messaging.destination.name', $traceContext['data']);
    }

    public function testNestedSyncDispatchDoesNotLeakChildDatabaseConnectionsOntoParent(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.job_context.queue_name' => true,
            'sentry.job_context.database' => true,
        ]);

        dispatch(new QueueJobContextTestNestedParentJob);

        $event = $this->getLastSentryEvent();

        $this->assertNotNull($event);

        $connectionsUsed = $event->getContexts()['laravel.job']['database']['connections_used'];

        // Before the fix `resetJobContext` wiped the parent's `connections_used`
        // when the child started and `finalizeJobContext` on the child left the
        // child's `child_conn` behind on the shared singleton fields. The parent's
        // reported context then showed the child's connection instead of its own.
        $this->assertContains(QueueJobContextTestNestedParentJob::PARENT_CONNECTION, $connectionsUsed);
        $this->assertNotContains(QueueJobContextTestNestedChildJob::CHILD_CONNECTION, $connectionsUsed);
    }

    public function testNestedSyncDispatchResumesParentDatabaseTrackingAfterChildFinishes(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.job_context.queue_name' => true,
            'sentry.job_context.database' => true,
        ]);

        dispatch(new QueueJobContextTestNestedParentJob);

        $connectionsUsed = $this->getLastSentryEvent()->getContexts()['laravel.job']['database']['connections_used'];

        // The parent runs a `PARENT_CONNECTION` query BEFORE dispatching the child
        // and another `PARENT_POST_CHILD_CONNECTION` query AFTER the child returns.
        // The post-child query was previously dropped because the child's
        // `finalizeJobContext()` set `jobContextTrackingDatabase = false` and the
        // parent never resumed tracking on its own.
        $this->assertContains(QueueJobContextTestNestedParentJob::PARENT_POST_CHILD_CONNECTION, $connectionsUsed);
    }

    public function testNestedSyncDispatchAssignsChildDataToChildSpanAndParentDataToParentSpan(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 1.0,
            'sentry.job_context.database' => true,
            'sentry.job_context.execution_time' => true,
        ]);

        dispatch(new QueueJobContextTestNestedTracingParentJob);

        $transactions = array_values(array_filter($this->getCapturedSentryEvents(), static function (array $event): bool {
            return $event[0]->getType() === EventType::transaction();
        }));

        // Nested inline dispatch shares the parent's transaction: the child is a
        // child span of the parent's `queue.process` transaction, not a
        // transaction of its own. So we expect exactly one transaction, and it
        // must be the parent's, carrying the parent's own measurements.
        $this->assertCount(1, $transactions);

        $parentTransaction = $transactions[0][0];
        $parentTrace = $parentTransaction->getContexts()['trace'];

        $this->assertSame('queue.process', $parentTrace['op']);
        $this->assertSame(QueueJobContextTestNestedTracingParentJob::class, $parentTransaction->getTransaction());

        // The parent's own connections must appear on the parent's span data, and the
        // child's isolated `child_conn` must not.
        $parentSpanConnections = $parentTrace['data']['job_context.db.connections_used'];

        $this->assertContains(QueueJobContextTestNestedTracingParentJob::PARENT_CONNECTION, $parentSpanConnections);
        $this->assertContains(QueueJobContextTestNestedTracingParentJob::PARENT_POST_CHILD_CONNECTION, $parentSpanConnections);
        $this->assertNotContains(QueueJobContextTestNestedChildJob::CHILD_CONNECTION, $parentSpanConnections);
    }
}

class QueueJobContextTestJob implements ShouldQueue
{
    public function handle(): void
    {
    }
}

class QueueJobContextTestJobThatReports implements ShouldQueue
{
    public function handle(): void
    {
        captureException(new Exception('This is a test exception'));
    }
}

class QueueJobContextTestJobThatThrows implements ShouldQueue
{
    public function handle(): void
    {
        throw new Exception('This is a test exception');
    }
}

class QueueJobContextTestJobThatQueriesDatabaseAndReports implements ShouldQueue
{
    public function handle(): void
    {
        event(new QueryExecuted('SELECT 1', [], 1, DB::connection()));

        captureException(new Exception('This is a test exception'));
    }
}

/**
 * Emits a `QueryExecuted` for a named, in-memory connection without requiring the
 * host application to have that connection actually configured. The listener the
 * job-context feature registers only reads `$event->connectionName`, which is a
 * plain public string on the event, so we can safely override it after construction.
 */
function queueJobContextTestEmitFakeQuery(string $connectionName): void
{
    $event = new QueryExecuted('SELECT 1', [], 1, DB::connection());
    $event->connectionName = $connectionName;

    event($event);
}

class QueueJobContextTestNestedChildJob implements ShouldQueue
{
    public const CHILD_CONNECTION = 'nested_child_conn';

    public function handle(): void
    {
        queueJobContextTestEmitFakeQuery(self::CHILD_CONNECTION);
    }
}

class QueueJobContextTestNestedParentJob implements ShouldQueue
{
    public const PARENT_CONNECTION = 'nested_parent_conn';
    public const PARENT_POST_CHILD_CONNECTION = 'nested_parent_post_child_conn';

    public function handle(): void
    {
        // Runs while the parent's job-context is the "current" one.
        queueJobContextTestEmitFakeQuery(self::PARENT_CONNECTION);

        // Dispatch the child inline via the sync driver so that its full
        // JobProcessing / JobProcessed lifecycle fires inside this handler.
        dispatch(new QueueJobContextTestNestedChildJob);

        // After the child has finished, the parent's job-context must be
        // restored so that this query is still recorded as part of the parent.
        queueJobContextTestEmitFakeQuery(self::PARENT_POST_CHILD_CONNECTION);

        captureException(new Exception('This is a test exception from the parent job'));
    }
}

class QueueJobContextTestNestedTracingParentJob implements ShouldQueue
{
    public const PARENT_CONNECTION = QueueJobContextTestNestedParentJob::PARENT_CONNECTION;
    public const PARENT_POST_CHILD_CONNECTION = QueueJobContextTestNestedParentJob::PARENT_POST_CHILD_CONNECTION;

    /**
     * Same as {@see QueueJobContextTestNestedParentJob} but without a final
     * captureException(). Capturing an event closes/sends the pending
     * transaction on the current scope, which would prevent the outer
     * `queue.process` transaction from being finished by the parent's
     * JobProcessed handler; the tracing regression test needs the transaction
     * intact so it can inspect the `job_context.*` span data written to it.
     */
    public function handle(): void
    {
        queueJobContextTestEmitFakeQuery(self::PARENT_CONNECTION);

        dispatch(new QueueJobContextTestNestedChildJob);

        queueJobContextTestEmitFakeQuery(self::PARENT_POST_CHILD_CONNECTION);
    }
}
