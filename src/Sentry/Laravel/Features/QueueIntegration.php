<?php

namespace Sentry\Laravel\Features;

use Closure;
use Illuminate\Support\Str;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Queue;
use Sentry\Breadcrumb;
use Sentry\DataCollection\DataCollectionPolicy;
use Sentry\DataCollection\KeyValueCollectionBehavior;
use Sentry\DataCollection\KeyValueDataFilter;
use Sentry\Laravel\Features\Concerns\TracksPushedScopesAndSpans;
use Sentry\Laravel\Integration;
use Sentry\SentrySdk;
use Sentry\Serializer\Serializer;
use Sentry\State\IsolationScope;
use Sentry\Tracing\PropagationContext;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TransactionContext;
use Sentry\Tracing\TransactionSource;

use function Sentry\continueTrace;
use function Sentry\getBaggage;
use function Sentry\getTraceparent;
use function Sentry\startTransaction;

class QueueIntegration extends Feature
{
    use TracksPushedScopesAndSpans {
        pushScope as private pushScopeTrait;
    }

    private const QUEUE_SPAN_OP_QUEUE_PUBLISH = 'queue.publish';

    private const QUEUE_PAYLOAD_BAGGAGE_DATA = 'sentry_baggage_data';
    private const QUEUE_PAYLOAD_TRACE_PARENT_DATA = 'sentry_trace_parent_data';
    private const QUEUE_PAYLOAD_PUBLISH_TIME = 'sentry_publish_time';

    private const QUEUE_SPAN_DATA_ARGUMENTS = 'messaging.message.body.data';

    /**
     * Whether the scope of the job that threw last is still pushed so the worker can report the exception with it.
     *
     * @var bool
     */
    private $failedJobScopeIsPushed = false;

    /**
     * The number of commands that were started while the scope of the failed job is pushed and did not finish yet.
     *
     * @var int
     */
    private $commandsStartedAfterJobFailed = 0;

    public function isApplicable(): bool
    {
        if (!$this->container()->bound('queue')) {
            return false;
        }

        return $this->isBreadcrumbFeatureEnabled('queue_info')
            || $this->isTracingFeatureEnabled('queue_jobs')
            || $this->isTracingFeatureEnabled('queue_job_transactions');
    }

    public function onBoot(Dispatcher $events): void
    {
        $events->listen(JobQueueing::class, [$this, 'handleJobQueueingEvent']);
        $events->listen(JobQueued::class, [$this, 'handleJobQueuedEvent']);

        $events->listen(JobProcessed::class, [$this, 'handleJobProcessedQueueEvent']);
        $events->listen(JobProcessing::class, [$this, 'handleJobProcessingQueueEvent']);
        $events->listen(WorkerStopping::class, [$this, 'handleWorkerStoppingQueueEvent']);
        $events->listen(JobExceptionOccurred::class, [$this, 'handleJobExceptionOccurredQueueEvent']);

        // The worker reports the exception of a failed job after `JobExceptionOccurred`, these are the first events after that
        $events->listen(Looping::class, [$this, 'handleWorkerLoopingQueueEvent']);
        $events->listen(CommandStarting::class, [$this, 'handleCommandStartingEvent']);
        $events->listen(CommandFinished::class, [$this, 'handleCommandFinishedEvent']);

        if ($this->isTracingFeatureEnabled('queue_jobs') || $this->isTracingFeatureEnabled('queue_job_transactions')) {
            Queue::createPayloadUsing(function (?string $connection, ?string $queue, ?array $payload): ?array {
                $parentSpan = SentrySdk::getIsolationScope()->getSpan();

                if ($parentSpan !== null && $parentSpan->getSampled()) {
                    $context = (new SpanContext)
                        ->setOp(self::QUEUE_SPAN_OP_QUEUE_PUBLISH)
                        ->setData(array_merge([
                            'messaging.system' => 'laravel',
                            'messaging.message.id' => $payload['uuid'] ?? null,
                            'messaging.destination.name' => $this->normalizeQueueName($queue),
                            'messaging.destination.connection' => $connection,
                        ], $this->collectPayloadArguments($payload)))
                        ->setDescription($queue);

                    $this->pushSpan($parentSpan->startChild($context));
                }

                if ($payload !== null) {
                    $payload[self::QUEUE_PAYLOAD_BAGGAGE_DATA] = getBaggage();
                    $payload[self::QUEUE_PAYLOAD_TRACE_PARENT_DATA] = getTraceparent();
                    $payload[self::QUEUE_PAYLOAD_PUBLISH_TIME] = microtime(true);
                }

                return $payload;
            });
        }
    }

    public function handleJobQueueingEvent(JobQueueing $event): void
    {
        $currentSpan = SentrySdk::getIsolationScope()->getSpan();

        // If there is no tracing span active there is no need to handle the event
        if ($currentSpan === null || $currentSpan->getOp() !== self::QUEUE_SPAN_OP_QUEUE_PUBLISH) {
            return;
        }

        $jobName = $event->job;

        if ($jobName instanceof Closure) {
            $jobName = 'Closure';
        } elseif (is_object($jobName)) {
            $jobName = get_class($jobName);
        }

        $currentSpan
            ->setDescription($jobName);
    }

    public function handleJobQueuedEvent(JobQueued $event): void
    {
        $this->maybeFinishSpan();
    }

    public function handleJobProcessedQueueEvent(JobProcessed $event): void
    {
        $this->maybeFinishSpan(SpanStatus::ok());

        if ($event->job instanceof SyncJob) {
            Integration::flushEvents();
        } else {
            $this->maybePopScope();
        }
    }

    public function handleJobProcessingQueueEvent(JobProcessing $event): void
    {
        // A sync job runs inside the request, command or job that dispatched it, so it shares that scope. It can also
        // run while the worker reports the exception of a failed job, so only the next worker job pops that scope.
        if (!$event->job instanceof SyncJob) {
            $this->popScopeOfFailedJob();
            $this->pushScope();
        }

        if ($this->isBreadcrumbFeatureEnabled('queue_info')) {
            $job = [
                'job' => $event->job->getName(),
                'queue' => $event->job->getQueue(),
                'attempts' => $event->job->attempts(),
                'connection' => $event->connectionName,
            ];

            // Resolve name exists only from Laravel 5.3+
            if (method_exists($event->job, 'resolveName')) {
                $job['resolved'] = $event->job->resolveName();
            }

            Integration::addBreadcrumb(new Breadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_DEFAULT,
                'queue.job',
                'Processing queue job',
                $job
            ));
        }

        $parentSpan = SentrySdk::getIsolationScope()->getSpan();

        // If there is no tracing span active and we don't trace jobs as transactions there is no need to handle the event
        if ($parentSpan === null && !$this->isTracingFeatureEnabled('queue_job_transactions')) {
            return;
        }

        // If there is a parent span we can record the job as a child unless the parent is not sample or we are configured to not do so
        if ($parentSpan !== null && (!$parentSpan->getSampled() || !$this->isTracingFeatureEnabled('queue_jobs'))) {
            return;
        }

        $jobPayload = $event->job->payload();

        if ($parentSpan === null) {
            $baggage = $jobPayload[self::QUEUE_PAYLOAD_BAGGAGE_DATA] ?? null;
            $traceParent = $jobPayload[self::QUEUE_PAYLOAD_TRACE_PARENT_DATA] ?? null;

            $context = continueTrace($traceParent ?? '', $baggage ?? '');

            // If the parent transaction was not sampled we also stop the queue job from being recorded
            if ($context->getParentSampled() === false) {
                return;
            }
        } else {
            $context = new SpanContext;
        }

        $resolvedJobName = $event->job->resolveName();

        $jobPublishedAt = $jobPayload[self::QUEUE_PAYLOAD_PUBLISH_TIME] ?? null;

        $job = [
            'messaging.system' => 'laravel',

            'messaging.destination.name' => $this->normalizeQueueName($event->job->getQueue()),
            'messaging.destination.connection' => $event->connectionName,

            'messaging.message.id' => $jobPayload['uuid'] ?? null,
            'messaging.message.envelope.size' => strlen($event->job->getRawBody()),
            'messaging.message.body.size' => strlen(json_encode($jobPayload['data'] ?? [])),
            'messaging.message.retry.count' => $event->job->attempts() - 1,
            'messaging.message.receive.latency' => $jobPublishedAt !== null ? microtime(true) - $jobPublishedAt : null,
        ];

        if ($context instanceof TransactionContext) {
            $context->setName($resolvedJobName);
            $context->setSource(TransactionSource::task());
        }

        $context->setOp('queue.process');
        $context->setData(array_merge($job, $this->collectPayloadArguments($jobPayload)));
        $context->setOrigin('auto.queue');
        $context->setStartTimestamp(microtime(true));

        // When the parent span is null we start a new transaction otherwise we start a child of the current span
        if ($parentSpan === null) {
            $span = startTransaction($context);
        } else {
            $span = $parentSpan->startChild($context);
        }

        $this->pushSpan($span);
    }

    public function handleWorkerLoopingQueueEvent(Looping $event): void
    {
        $this->popScopeOfFailedJob();
    }

    public function handleWorkerStoppingQueueEvent(WorkerStopping $event): void
    {
        $this->popScopeOfFailedJob();

        Integration::flushEvents();
    }

    public function handleCommandStartingEvent(CommandStarting $event): void
    {
        // A command called while the worker reports the exception of a failed job finishes before the worker moves on
        if ($this->failedJobScopeIsPushed) {
            ++$this->commandsStartedAfterJobFailed;
        }
    }

    public function handleCommandFinishedEvent(CommandFinished $event): void
    {
        if ($this->commandsStartedAfterJobFailed > 0) {
            --$this->commandsStartedAfterJobFailed;

            return;
        }

        $this->popScopeOfFailedJob();
    }

    public function handleJobExceptionOccurredQueueEvent(JobExceptionOccurred $event): void
    {
        $this->maybeFinishSpan(SpanStatus::internalError());

        Integration::flushEvents();

        // The worker reports the exception after this event, so the scope of the job stays pushed until the worker moves on
        if (!$event->job instanceof SyncJob && $this->pushedScopeCount > 0) {
            $this->failedJobScopeIsPushed = true;
        }
    }

    private function popScopeOfFailedJob(): void
    {
        if (!$this->failedJobScopeIsPushed) {
            return;
        }

        $this->failedJobScopeIsPushed = false;
        $this->commandsStartedAfterJobFailed = 0;

        $this->maybePopScope();
    }

    /**
     * @param array<array-key, mixed>|null $payload
     *
     * @return array<string, mixed>
     */
    private function collectPayloadArguments(?array $payload): array
    {
        $data = $payload['data'] ?? null;

        if (!is_array($data) || $data === [] || isset($data['commandName'], $data['command'])) {
            return [];
        }

        $policy = DataCollectionPolicy::fromOptions(SentrySdk::getClient()->getOptions());
        $dataCollection = $policy->getDataCollection();

        // The legacy options never collected the arguments passed to queued jobs
        if ($dataCollection === null || !$dataCollection->shouldCollectQueues()) {
            return [];
        }

        $filter = new KeyValueDataFilter(KeyValueCollectionBehavior::denyList());
        $filtered = $filter->filterKeyValueData($data, [new Serializer($policy->getOptions()), 'serialize']);

        return [
            self::QUEUE_SPAN_DATA_ARGUMENTS => json_encode($filtered) ?: KeyValueDataFilter::FILTERED_VALUE,
        ];
    }

    private function normalizeQueueName(?string $queue): string
    {
        if ($queue === null) {
            return '';
        }

        // SQS queues are sometimes formatted like: https://sqs.<region>.amazonaws.com/<id>/<queue_name>
        if (filter_var($queue, FILTER_VALIDATE_URL) !== false) {
            return Str::afterLast($queue, '/');
        }

        // Jobs pushed onto the Redis driver are formatted as queues:<queue>
        return Str::after($queue, 'queues:');
    }

    protected function pushScope(): IsolationScope
    {
        $scope = $this->pushScopeTrait();

        // When a job starts, we want to make sure the scope is cleared of breadcrumbs
        // as well as setting a new propagation context.
        $scope->clearBreadcrumbs();
        $scope->setPropagationContext(PropagationContext::fromDefaults());

        return $scope;
    }
}
