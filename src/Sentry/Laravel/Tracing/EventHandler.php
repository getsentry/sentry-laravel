<?php

namespace Sentry\Laravel\Tracing;

use Exception;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events as DatabaseEvents;
use Illuminate\Queue\Events as QueueEvents;
use Illuminate\Queue\QueueManager;
use RuntimeException;
use Sentry\Laravel\Integration;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TransactionContext;

class EventHandler
{
    /**
     * Map event handlers to events.
     *
     * @var array
     */
    protected static $eventHandlerMap = [
        'illuminate.query' => 'query',                          // Until Laravel 5.1
        DatabaseEvents\QueryExecuted::class => 'queryExecuted', // Since Laravel 5.2
    ];

    /**
     * Map queue event handlers to events.
     *
     * @var array
     */
    protected static $queueEventHandlerMap = [
        QueueEvents\JobProcessing::class => 'queueJobProcessing',               // Since Laravel 5.2
        QueueEvents\JobProcessed::class => 'queueJobProcessed',                 // Since Laravel 5.2
        QueueEvents\JobExceptionOccurred::class => 'queueJobExceptionOccurred', // Since Laravel 5.2
    ];

    /**
     * The Laravel container.
     *
     * @var \Illuminate\Contracts\Container\Container
     */
    private $container;

    /**
     * Indicates if we should we add SQL queries as spans.
     *
     * @var bool
     */
    private $traceSqlQueries;

    /**
     * Indicates if we should trace queue job spans.
     *
     * @var bool
     */
    private $traceQueueJobs;

    /**
     * Indicates if we should trace queue jobs as separate transactions.
     *
     * @var bool
     */
    private $traceQueueJobsAsTransactions;

    /**
     * Holds a reference to the parent queue job span.
     *
     * @var \Sentry\Tracing\Span|null
     */
    private $parentQueueJobSpan;

    /**
     * Holds a reference to the current queue job span or transaction.
     *
     * @var \Sentry\Tracing\Transaction|\Sentry\Tracing\Span|null
     */
    private $currentQueueJobSpan;

    /**
     * The backtrace helper.
     *
     * @var \Sentry\Laravel\Tracing\BacktraceHelper
     */
    private $backtraceHelper;

    /**
     * EventHandler constructor.
     *
     * @param \Illuminate\Contracts\Container\Container $container
     * @param \Sentry\Laravel\Tracing\BacktraceHelper   $backtraceHelper
     * @param array                                     $config
     */
    public function __construct(Container $container, BacktraceHelper $backtraceHelper, array $config)
    {
        $this->container = $container;
        $this->backtraceHelper = $backtraceHelper;

        $this->traceSqlQueries = ($config['sql_queries'] ?? true) === true;
        $this->traceQueueJobs = ($config['queue_jobs'] ?? false) === true;
        $this->traceQueueJobsAsTransactions = ($config['queue_job_transactions'] ?? false) === true;
    }

    /**
     * Attach all event handlers.
     */
    public function subscribe(): void
    {
        try {
            /** @var \Illuminate\Contracts\Events\Dispatcher $dispatcher */
            $dispatcher = $this->container->make(Dispatcher::class);

            foreach (static::$eventHandlerMap as $eventName => $handler) {
                $dispatcher->listen($eventName, [$this, $handler]);
            }
        } catch (BindingResolutionException $e) {
            // If we cannot resolve the event dispatcher we also cannot listen to events
        }
    }

    /**
     * Attach all queue event handlers.
     *
     * @param \Illuminate\Queue\QueueManager $queue
     */
    public function subscribeQueueEvents(QueueManager $queue): void
    {
        // If both types of queue job tracing is disabled also do not register the events
        if (!$this->traceQueueJobs && !$this->traceQueueJobsAsTransactions) {
            return;
        }

        $queue->looping(function () {
            $this->afterQueuedJob();
        });

        try {
            /** @var \Illuminate\Contracts\Events\Dispatcher $dispatcher */
            $dispatcher = $this->container->make(Dispatcher::class);

            foreach (static::$queueEventHandlerMap as $eventName => $handler) {
                $dispatcher->listen($eventName, [$this, $handler]);
            }
        } catch (BindingResolutionException $e) {
            // If we cannot resolve the event dispatcher we also cannot listen to events
        }
    }

    /**
     * Pass through the event and capture any errors.
     *
     * @param string $method
     * @param array  $arguments
     */
    public function __call($method, $arguments)
    {
        $handlerMethod = "{$method}Handler";

        if (!method_exists($this, $handlerMethod)) {
            throw new RuntimeException("Missing tracing event handler: {$handlerMethod}");
        }

        try {
            call_user_func_array([$this, $handlerMethod], $arguments);
        } catch (Exception $exception) {
            // Ignore
        }
    }

    /**
     * Until Laravel 5.1
     *
     * @param string $query
     * @param array  $bindings
     * @param int    $time
     * @param string $connectionName
     */
    protected function queryHandler($query, $bindings, $time, $connectionName): void
    {
        $this->recordQuerySpan($query, $time);
    }

    /**
     * Since Laravel 5.2
     *
     * @param \Illuminate\Database\Events\QueryExecuted $query
     */
    protected function queryExecutedHandler(DatabaseEvents\QueryExecuted $query): void
    {
        $this->recordQuerySpan($query->sql, $query->time);
    }

    /**
     * Helper to add an query breadcrumb.
     *
     * @param string     $query
     * @param float|null $time
     */
    private function recordQuerySpan($query, $time): void
    {
        if (!$this->traceSqlQueries) {
            return;
        }

        $parentSpan = Integration::currentTracingSpan();

        // If there is no tracing span active there is no need to handle the event
        if ($parentSpan === null) {
            return;
        }

        $context = new SpanContext();
        $context->setOp('sql.query');
        $context->setDescription($query);
        $context->setStartTimestamp(microtime(true) - $time / 1000);
        $context->setEndTimestamp($context->getStartTimestamp() + $time / 1000);

        $queryOrigin = $this->resolveQueryOriginFromBacktrace($context);

        if ($queryOrigin !== null) {
            $context->setData(['sql.origin' => $queryOrigin]);
        }

        $parentSpan->startChild($context);
    }

    /**
     * Try to find the origin of the SQL query that was just executed.
     *
     * @return string|null
     */
    private function resolveQueryOriginFromBacktrace(): ?string
    {
        $firstAppFrame = $this->backtraceHelper->findFirstInAppFrameForBacktrace(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));

        if ($firstAppFrame === null) {
            return null;
        }

        $filePath = $this->backtraceHelper->getOriginalViewPathForFrameOfCompiledViewPath($firstAppFrame) ?? $firstAppFrame->getFile();

        return "{$filePath}:{$firstAppFrame->getLine()}";
    }

    /*
     * Since Laravel 5.2
     *
     * @param \Illuminate\Queue\Events\JobProcessing $event
     */
    protected function queueJobProcessingHandler(QueueEvents\JobProcessing $event)
    {
        $parentSpan = Integration::currentTracingSpan();

        // If there is no tracing span active and we don't trace jobs as transactions there is no need to handle the event
        if ($parentSpan === null && !$this->traceQueueJobsAsTransactions) {
            return;
        }

        // If there is a parent span we can record that job as a child unless configured to not do so
        if ($parentSpan !== null && !$this->traceQueueJobs) {
            return;
        }

        $this->parentQueueJobSpan = $parentSpan;

        $spanContext = $parentSpan === null
            ? new TransactionContext(
                method_exists($event->job, 'resolveName')
                    ? $event->job->resolveName()
                    : $event->job->getName()
            )
            : new SpanContext();

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

        $spanContext->setOp('queue.job');
        $spanContext->setData($job);
        $spanContext->setStartTimestamp(microtime(true));

        // When the parent span is null we start a new transaction otherwise we start a child of the current span
        if ($parentSpan === null) {
            $this->currentQueueJobSpan = SentrySdk::getCurrentHub()->startTransaction($spanContext);
        } else {
            $this->currentQueueJobSpan = $parentSpan->startChild($spanContext);
        }

        SentrySdk::getCurrentHub()->setSpan($this->currentQueueJobSpan);
    }

    /**
     * Since Laravel 5.2
     *
     * @param \Illuminate\Queue\Events\JobExceptionOccurred $event
     */
    protected function queueJobExceptionOccurredHandler(QueueEvents\JobExceptionOccurred $event)
    {
        $this->afterQueuedJob(SpanStatus::internalError());
    }

    /**
     * Since Laravel 5.2
     *
     * @param \Illuminate\Queue\Events\JobProcessed $event
     */
    protected function queueJobProcessedHandler(QueueEvents\JobProcessed $event)
    {
        $this->afterQueuedJob(SpanStatus::ok());
    }

    private function afterQueuedJob(?SpanStatus $status = null): void
    {
        if ($this->currentQueueJobSpan === null) {
            return;
        }

        $this->currentQueueJobSpan->setStatus($status);
        $this->currentQueueJobSpan->finish();
        $this->currentQueueJobSpan = null;

        SentrySdk::getCurrentHub()->setSpan($this->parentQueueJobSpan);
        $this->parentQueueJobSpan = null;
    }
}
