<?php

namespace Sentry\Laravel\Tests\Features;

use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
