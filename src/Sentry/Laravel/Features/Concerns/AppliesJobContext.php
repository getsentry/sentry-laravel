<?php

namespace Sentry\Laravel\Features\Concerns;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobProcessing;
use Sentry\Event as SentryEvent;
use Sentry\EventHint;
use Sentry\Laravel\Integration;
use Sentry\SentrySdk;
use Sentry\State\Scope;

/**
 * Attaches extra tags and a structured `laravel.job` context (memory usage, queue identity,
 * database connections, execution time, attempts and Horizon metadata) scoped to the queue
 * job that is currently being processed.
 *
 * @internal
 */
trait AppliesJobContext
{
    /**
     * Wall-clock start time (as returned by `microtime(true)`) of the currently processing job.
     *
     * @var float|null
     */
    private $jobContextStartTime;

    /**
     * Memory usage (in bytes) at the start of the currently processing job.
     *
     * @var int|null
     */
    private $jobContextStartMemory;

    /**
     * Unique database connection names queried during the currently processing job.
     *
     * @var array<int, string>
     */
    private $jobContextConnectionsUsed = [];

    /**
     * Whether database queries should currently be recorded for job context purposes.
     *
     * @var bool
     */
    private $jobContextTrackingDatabase = false;

    /**
     * Whether the database query listener has already been registered.
     *
     * @var bool
     */
    private $jobContextDatabaseListenerRegistered = false;

    /**
     * Horizon specific metadata extracted from the job payload, if any, for the job
     * currently being processed.
     *
     * @var array<string, mixed>|null
     */
    private $jobContextHorizonData;

    /**
     * Register the listener used to track database connections used during a job.
     *
     * This is a no-op if it has already been registered or if the `database` job
     * context feature is not enabled.
     */
    protected function registerJobContextDatabaseListener(Dispatcher $events): void
    {
        if ($this->jobContextDatabaseListenerRegistered || !$this->isJobContextFeatureEnabled('database')) {
            return;
        }

        $events->listen(QueryExecuted::class, function (QueryExecuted $query): void {
            if (!$this->jobContextTrackingDatabase) {
                return;
            }

            if (!in_array($query->connectionName, $this->jobContextConnectionsUsed, true)) {
                $this->jobContextConnectionsUsed[] = $query->connectionName;
            }
        });

        $this->jobContextDatabaseListenerRegistered = true;
    }

    /**
     * Snapshot the state of the job at the start of processing and register the tags
     * and event processor that will enrich any event captured while this job is running.
     */
    protected function captureJobContext(JobProcessing $event): void
    {
        if (!$this->isAnyJobContextFeatureEnabled()) {
            return;
        }

        $job = $event->job;

        if ($this->isJobContextFeatureEnabled('execution_time')) {
            $this->jobContextStartTime = microtime(true);
        }

        if ($this->isJobContextFeatureEnabled('memory_usage')) {
            $this->jobContextStartMemory = memory_get_usage(true);

            // Reset the peak memory marker so `memory_get_peak_usage()` reflects only this
            // job on long-running workers. Not available before PHP 8.2, and skipped for the
            // `sync` connection since that runs inline in the current (HTTP/console) process.
            if ($event->connectionName !== 'sync' && function_exists('memory_reset_peak_usage')) {
                memory_reset_peak_usage();
            }
        }

        if ($this->isJobContextFeatureEnabled('database')) {
            $this->jobContextConnectionsUsed = [];
            $this->jobContextTrackingDatabase = true;
        }

        if ($this->isJobContextFeatureEnabled('horizon')) {
            $this->jobContextHorizonData = $this->extractHorizonJobData($job->payload());
        }

        Integration::configureScope(function (Scope $scope) use ($job, $event): void {
            if ($this->isJobContextFeatureEnabled('queue_name')) {
                $scope->setTag('queue', $this->normalizeQueueName($job->getQueue()));
                $scope->setTag('queue.connection', $event->connectionName);

                if (method_exists($job, 'resolveName')) {
                    $scope->setTag('job', $job->resolveName());
                }
            }

            if ($this->isJobContextFeatureEnabled('attempts')) {
                $scope->setTag('attempts', (string) $job->attempts());
            }

            if (isset($this->jobContextHorizonData['supervisor'])) {
                $scope->setTag('horizon.supervisor', $this->jobContextHorizonData['supervisor']);
            }

            $scope->addEventProcessor(function (SentryEvent $sentryEvent, ?EventHint $hint = null) use ($event): SentryEvent {
                return $this->applyJobContextToEvent($sentryEvent, $event);
            });
        });
    }

    /**
     * Merge the collected job context measurements onto the currently active span (if any)
     * that was pushed for the job that just finished.
     */
    protected function finalizeJobContext(): void
    {
        $this->jobContextTrackingDatabase = false;

        if (!$this->isAnyJobContextFeatureEnabled()) {
            return;
        }

        // Only attach job context to a span if this job actually pushed one onto the hub.
        // Otherwise (e.g. queue tracing disabled) the "current" span could belong to an
        // unrelated parent transaction (like an HTTP request running the `sync` driver).
        if (!$this->hasPushedSpan()) {
            return;
        }

        $span = SentrySdk::getCurrentHub()->getSpan();

        if ($span === null) {
            return;
        }

        // Existing `messaging.*` span data (queue name, connection, retry count, etc.) is
        // preserved
        $data = $this->buildJobContextForSpan();

        if (!empty($data)) {
            $span->setData($data);
        }
    }

    /**
     * Reset any state left over from a previous job (used before starting a new job).
     */
    protected function resetJobContext(): void
    {
        $this->jobContextStartTime = null;
        $this->jobContextStartMemory = null;
        $this->jobContextConnectionsUsed = [];
        $this->jobContextTrackingDatabase = false;
        $this->jobContextHorizonData = null;
    }

    /**
     * Build the `laravel.job` structured context for the event currently being captured.
     */
    private function applyJobContextToEvent(SentryEvent $event, JobProcessing $jobEvent): SentryEvent
    {
        $context = [];

        if ($this->isJobContextFeatureEnabled('queue_name')) {
            $context['queue'] = $this->normalizeQueueName($jobEvent->job->getQueue());
            $context['connection'] = $jobEvent->connectionName;
        }

        if ($this->isJobContextFeatureEnabled('attempts')) {
            $context['attempts'] = $jobEvent->job->attempts();
        }

        if ($this->isJobContextFeatureEnabled('execution_time') && $this->jobContextStartTime !== null) {
            $context['execution_time_ms'] = round((microtime(true) - $this->jobContextStartTime) * 1000, 2);
        }

        if ($this->isJobContextFeatureEnabled('memory_usage') && $this->jobContextStartMemory !== null) {
            $context['memory'] = [
                'start' => $this->jobContextStartMemory,
                'end' => memory_get_usage(true),
                'peak' => memory_get_peak_usage(true),
                'limit' => ini_get('memory_limit'),
            ];
        }

        if ($this->isJobContextFeatureEnabled('database')) {
            $context['database'] = [
                'default' => $this->container()['config']->get('database.default'),
                'connections_used' => $this->jobContextConnectionsUsed,
            ];
        }

        if ($this->jobContextHorizonData !== null) {
            $context['horizon'] = $this->jobContextHorizonData;
        }

        if (empty($context)) {
            return $event;
        }

        $event->setContext('laravel.job', $context);

        return $event;
    }

    /**
     * Build the flat, namespaced span data additions for the job currently being processed.
     *
     * @return array<string, mixed>
     */
    private function buildJobContextForSpan(): array
    {
        $data = [];

        if ($this->isJobContextFeatureEnabled('execution_time') && $this->jobContextStartTime !== null) {
            $data['job_context.execution_time_ms'] = round((microtime(true) - $this->jobContextStartTime) * 1000, 2);
        }

        if ($this->isJobContextFeatureEnabled('memory_usage') && $this->jobContextStartMemory !== null) {
            $data['job_context.memory.start'] = $this->jobContextStartMemory;
            $data['job_context.memory.end'] = memory_get_usage(true);
            $data['job_context.memory.peak'] = memory_get_peak_usage(true);
            $data['job_context.memory.limit'] = ini_get('memory_limit');
        }

        if ($this->isJobContextFeatureEnabled('database')) {
            $data['job_context.db.default_connection'] = $this->container()['config']->get('database.default');
            $data['job_context.db.connections_used'] = $this->jobContextConnectionsUsed;
        }

        if ($this->jobContextHorizonData !== null) {
            $data['job_context.horizon'] = $this->jobContextHorizonData;
        }

        return $data;
    }

    /**
     * Extract Laravel Horizon specific job metadata from the job payload, if present.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|null
     */
    private function extractHorizonJobData(array $payload): ?array
    {
        // `displayName`, `maxTries` and `timeout` are standard Laravel queue payload keys
        // present on every job regardless of Horizon, so only genuinely Horizon-specific
        // keys (set by `Laravel\Horizon\JobPayload::prepare()`) are used here.
        $horizonKeys = ['type', 'tags', 'silenced', 'pushedAt'];

        $horizon = [];

        foreach ($horizonKeys as $key) {
            if (array_key_exists($key, $payload)) {
                $horizon[$key] = $payload[$key];
            }
        }

        // None of the known Horizon payload keys were present, this job was most likely not
        // queued through Horizon so there is nothing meaningful to attach.
        if (empty($horizon)) {
            return null;
        }

        $supervisor = $this->resolveHorizonSupervisorName();

        if ($supervisor !== null) {
            $horizon['supervisor'] = $supervisor;
        }

        return $horizon;
    }

    /**
     * Attempt to resolve the name of the Horizon supervisor managing the current worker
     * process from the `horizon:work` / `horizon:supervisor` command line arguments.
     */
    private function resolveHorizonSupervisorName(): ?string
    {
        if (!isset($_SERVER['argv']) || !is_array($_SERVER['argv'])) {
            return null;
        }

        foreach ($_SERVER['argv'] as $argument) {
            if (is_string($argument) && strncmp($argument, '--supervisor=', 13) === 0) {
                return substr($argument, 13);
            }
        }

        return null;
    }
}
