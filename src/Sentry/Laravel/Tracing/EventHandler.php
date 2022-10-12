<?php

namespace Sentry\Laravel\Tracing;

use Exception;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events as DatabaseEvents;
use Illuminate\Http\Client\Events as HttpClientEvents;
use Illuminate\Queue\Events as QueueEvents;
use Illuminate\Queue\Queue;
use Illuminate\Queue\QueueManager;
use Illuminate\Routing\Events as RoutingEvents;
use RuntimeException;
use Sentry\Laravel\Integration;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TransactionContext;
use Sentry\Tracing\TransactionSource;

class EventHandler
{
    public const QUEUE_PAYLOAD_TRACE_PARENT_DATA = 'sentry_trace_parent_data';

    public const QUEUE_PAYLOAD_BAGGAGE_DATA = 'sentry_baggage_data';

    /**
     * Map event handlers to events.
     *
     * @var array
     */
    protected static $eventHandlerMap = [
        RoutingEvents\RouteMatched::class => 'routeMatched',
        DatabaseEvents\QueryExecuted::class => 'queryExecuted',
        HttpClientEvents\RequestSending::class => 'httpClientRequestSending',
        HttpClientEvents\ResponseReceived::class => 'httpClientResponseReceived',
        HttpClientEvents\ConnectionFailed::class => 'httpClientConnectionFailed',
    ];

    /**
     * Map queue event handlers to events.
     *
     * @var array
     */
    protected static $queueEventHandlerMap = [
        QueueEvents\JobProcessing::class => 'queueJobProcessing',
        QueueEvents\JobProcessed::class => 'queueJobProcessed',
        QueueEvents\JobExceptionOccurred::class => 'queueJobExceptionOccurred',
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
     * Indicates if we should we add SQL query origin data to query spans.
     *
     * @var bool
     */
    private $traceSqlQueryOrigins;

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
     * Holds a reference to the parent http client request span.
     *
     * @var \Sentry\Tracing\Span|null
     */
    private $parentHttpClientRequestSpan;

    /**
     * Holds a reference to the current http client request span.
     *
     * @var \Sentry\Tracing\Span|null
     */
    private $currentHttpClientRequestSpan;

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
        $this->traceSqlQueryOrigins = ($config['sql_origin'] ?? true) === true;

        $this->traceQueueJobs = ($config['queue_jobs'] ?? false) === true;
        $this->traceQueueJobsAsTransactions = ($config['queue_job_transactions'] ?? false) === true;
    }

    /**
     * Attach all event handlers.
     *
     * @uses self::routeMatchedHandler()
     * @uses self::queryExecutedHandler()
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
     *
     * @uses self::queueJobProcessingHandler()
     * @uses self::queueJobProcessedHandler()
     * @uses self::queueJobExceptionOccurredHandler()
     */
    public function subscribeQueueEvents(QueueManager $queue): void
    {
        // If both types of queue job tracing is disabled also do not register the events
        if (!$this->traceQueueJobs && !$this->traceQueueJobsAsTransactions) {
            return;
        }

        Queue::createPayloadUsing(static function (?string $connection, ?string $queue, ?array $payload): ?array {
            $currentSpan = Integration::currentTracingSpan();

            if ($currentSpan !== null && $payload !== null) {
                $payload[self::QUEUE_PAYLOAD_TRACE_PARENT_DATA] = $currentSpan->toTraceparent();
                $payload[self::QUEUE_PAYLOAD_BAGGAGE_DATA] = $currentSpan->toBaggage();
            }

            return $payload;
        });

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
    public function __call(string $method, array $arguments)
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

    protected function routeMatchedHandler(RoutingEvents\RouteMatched $match): void
    {
        $transaction = Integration::currentTransaction();

        if ($transaction === null) {
            return;
        }

        [$transactionName, $transactionSource] = Integration::extractNameAndSourceForRoute($match->route);

        $transaction->setName($transactionName);
        $transaction->getMetadata()->setSource($transactionSource);
    }

    protected function queryExecutedHandler(DatabaseEvents\QueryExecuted $query): void
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
        $context->setOp('db.sql.query');
        $context->setDescription($query->sql);
        $context->setStartTimestamp(microtime(true) - $query->time / 1000);
        $context->setEndTimestamp($context->getStartTimestamp() + $query->time / 1000);

        if ($this->traceSqlQueryOrigins) {
            $queryOrigin = $this->resolveQueryOriginFromBacktrace();

            if ($queryOrigin !== null) {
                $context->setData(['sql.origin' => $queryOrigin]);
            }
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

    protected function httpClientRequestSendingHandler(HttpClientEvents\RequestSending $event): void
    {
        $parentSpan = Integration::currentTracingSpan();

        if ($parentSpan === null) {
            return;
        }

        $context = new SpanContext;

        $context->setOp('http.client');
        $context->setDescription($event->request->method() . ' ' . $event->request->url());
        $context->setStartTimestamp(microtime(true));

        $this->currentHttpClientRequestSpan = $parentSpan->startChild($context);

        $this->parentHttpClientRequestSpan = $parentSpan;

        SentrySdk::getCurrentHub()->setSpan($this->currentHttpClientRequestSpan);
    }

    protected function httpClientResponseReceivedHandler(HttpClientEvents\ResponseReceived $event): void
    {
        if ($this->currentHttpClientRequestSpan !== null) {
            $this->currentHttpClientRequestSpan->setHttpStatus($event->response->status());
            $this->afterHttpClientRequest();
        }
    }

    protected function httpClientConnectionFailedHandler(HttpClientEvents\ConnectionFailed $event): void
    {
        if ($this->currentHttpClientRequestSpan !== null) {
            $this->currentHttpClientRequestSpan->setStatus(SpanStatus::internalError());
            $this->afterHttpClientRequest();
        }
    }

    private function afterHttpClientRequest(): void
    {
        if ($this->currentHttpClientRequestSpan === null) {
            return;
        }

        $this->currentHttpClientRequestSpan->finish();
        $this->currentHttpClientRequestSpan = null;

        SentrySdk::getCurrentHub()->setSpan($this->parentHttpClientRequestSpan);
        $this->parentHttpClientRequestSpan = null;
    }

    protected function queueJobProcessingHandler(QueueEvents\JobProcessing $event): void
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

        if ($parentSpan === null) {
            $baggage = $event->job->payload()[self::QUEUE_PAYLOAD_BAGGAGE_DATA] ?? null;
            $traceParent = $event->job->payload()[self::QUEUE_PAYLOAD_TRACE_PARENT_DATA] ?? null;

            $context = TransactionContext::fromHeaders($traceParent ?? '', $baggage ?? '');

            // If the parent transaction was not sampled we also stop the queue job from being recorded
            if ($context->getParentSampled() === false) {
                return;
            }
        } else {
            $context = new SpanContext;
        }

        $resolvedJobName = $event->job->resolveName();

        $job = [
            'job' => $event->job->getName(),
            'queue' => $event->job->getQueue(),
            'resolved' => $event->job->resolveName(),
            'attempts' => $event->job->attempts(),
            'connection' => $event->connectionName,
        ];

        if ($context instanceof TransactionContext) {
            $context->setName($resolvedJobName);
            $context->setSource(TransactionSource::task());
        }

        $context->setOp('queue.process');
        $context->setData($job);
        $context->setStartTimestamp(microtime(true));

        // When the parent span is null we start a new transaction otherwise we start a child of the current span
        if ($parentSpan === null) {
            $this->currentQueueJobSpan = SentrySdk::getCurrentHub()->startTransaction($context);
        } else {
            $this->currentQueueJobSpan = $parentSpan->startChild($context);
        }

        $this->parentQueueJobSpan = $parentSpan;

        SentrySdk::getCurrentHub()->setSpan($this->currentQueueJobSpan);
    }

    protected function queueJobExceptionOccurredHandler(QueueEvents\JobExceptionOccurred $event): void
    {
        $this->afterQueuedJob(SpanStatus::internalError());
    }

    protected function queueJobProcessedHandler(QueueEvents\JobProcessed $event): void
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
