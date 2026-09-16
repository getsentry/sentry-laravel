<?php

namespace Sentry\Laravel\Tests\Features;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use Sentry\Laravel\Features\QueueIntegration;
use Sentry\Laravel\Tests\TestCase;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\Transaction;
use Sentry\Util\JSON;

class QueueDataCollectionTest extends TestCase
{
    protected function tearDown(): void
    {
        Queue::createPayloadUsing(null);

        parent::tearDown();
    }

    /**
     * @dataProvider sendDefaultPiiProvider
     */
    public function testConfiguredDefaultsCollectPayloadForBreadcrumbsAndChildSpans(bool $sendDefaultPii): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
            'sentry.send_default_pii' => $sendDefaultPii,
            'sentry.traces_sample_rate' => 1.0,
        ]);
        $transaction = $this->startTransaction();
        $integration = new QueueIntegration($this->app);
        $payload = [
            'uuid' => 'message-id',
            'data' => [
                'name' => 'Alice',
                'password' => 'secret',
                'nested' => ['api_token' => 'secret'],
                'commandName' => 'App\\Jobs\\ExampleJob',
                'command' => 'O:19:"App\\Jobs\\ExampleJob":0:{}',
            ],
        ];

        $integration->handleJobProcessingQueueEvent(new JobProcessing('redis', $this->job($payload, 'queues:emails', 3)));

        $expectedPayload = $payload['data'];
        $this->assertSame($expectedPayload, $this->getLastSentryBreadcrumb()->getMetadata()['messaging.message.body.data']);

        $span = SentrySdk::getCurrentHub()->getSpan();
        $this->assertInstanceOf(Span::class, $span);
        $this->assertNotSame($transaction, $span);
        $this->assertSame($expectedPayload, $span->getData()['messaging.message.body.data']);
        $this->assertSame('emails', $span->getData()['messaging.destination.name']);
        $this->assertSame('redis', $span->getData()['messaging.destination.connection']);
        $this->assertSame('message-id', $span->getData()['messaging.message.id']);
        $this->assertSame(2, $span->getData()['messaging.message.retry.count']);
    }

    /**
     * @dataProvider stringPayloadProvider
     */
    public function testStringPayloadIsCollectedUnchanged(string $payloadData): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
            'sentry.tracing.queue_jobs' => false,
            'sentry.tracing.queue_job_transactions' => false,
        ]);
        $integration = new QueueIntegration($this->app);

        $integration->handleJobProcessingQueueEvent(new JobProcessing('sync', $this->job([
            'data' => $payloadData,
        ])));

        $this->assertSame($payloadData, $this->getLastSentryBreadcrumb()->getMetadata()['messaging.message.body.data']);
    }

    public static function stringPayloadProvider(): iterable
    {
        yield 'JSON' => [JSON::encode(['name' => 'Alice', 'password' => 'secret'])];
        yield 'plain string' => ['plain string'];
    }

    /**
     * @dataProvider disabledPolicyProvider
     *
     * @param array<string, mixed>|null $dataCollection
     */
    public function testDisabledAndLegacyPoliciesOmitPayloadButKeepOperationalMetadata(?array $dataCollection, bool $sendDefaultPii): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => $dataCollection,
            'sentry.send_default_pii' => $sendDefaultPii,
            'sentry.traces_sample_rate' => 1.0,
        ]);
        $this->startTransaction();
        $integration = new QueueIntegration($this->app);
        $payload = ['uuid' => 'message-id', 'data' => ['name' => 'Alice']];

        $integration->handleJobProcessingQueueEvent(new JobProcessing('sync', $this->job($payload, 'default', 2)));

        $breadcrumb = $this->getLastSentryBreadcrumb()->getMetadata();
        $span = SentrySdk::getCurrentHub()->getSpan();
        $this->assertArrayNotHasKey('messaging.message.body.data', $breadcrumb);
        $this->assertSame('default', $breadcrumb['queue']);
        $this->assertSame(2, $breadcrumb['attempts']);
        $this->assertArrayNotHasKey('messaging.message.body.data', $span->getData());
        $this->assertSame('message-id', $span->getData()['messaging.message.id']);
        $this->assertSame('sync', $span->getData()['messaging.destination.connection']);
    }

    public static function disabledPolicyProvider(): iterable
    {
        yield 'legacy without PII' => [null, false];
        yield 'legacy with PII' => [null, true];
        yield 'disabled without PII' => [['queues' => false], false];
        yield 'disabled with PII' => [['queues' => false], true];
    }

    public function testPublishingCollectsPayloadAndPreservesTransportData(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => ['queues' => true],
            'sentry.traces_sample_rate' => 1.0,
        ]);
        Queue::createPayloadUsing(null);
        $integration = new QueueIntegration($this->app);
        $integration->onBoot($this->createMock(Dispatcher::class));
        $transaction = $this->startTransaction();
        $command = new QueueDataCollectionThrowingSerializable();
        $payload = [
            'uuid' => 'publish-id',
            'data' => [
                'commandName' => QueueDataCollectionThrowingSerializable::class,
                'command' => $command,
                'password' => 'secret',
            ],
        ];
        $queue = (new QueueDataCollectionTestQueue())->setConnectionName('redis');

        $transportPayload = $queue->applyPayloadHooks('queues:emails', $payload);

        $span = SentrySdk::getCurrentHub()->getSpan();
        $this->assertInstanceOf(Span::class, $span);
        $this->assertNotSame($transaction, $span);
        $this->assertSame($payload['data'], $span->getData()['messaging.message.body.data']);
        $this->assertSame('publish-id', $span->getData()['messaging.message.id']);
        $this->assertSame('emails', $span->getData()['messaging.destination.name']);
        $this->assertSame($command, $payload['data']['command']);
        $this->assertSame($command, $transportPayload['data']['command']);
        $this->assertArrayHasKey('sentry_baggage_data', $transportPayload);
        $this->assertArrayHasKey('sentry_trace_parent_data', $transportPayload);
        $this->assertArrayHasKey('sentry_publish_time', $transportPayload);
    }

    public function testPublishingPolicyChangesDoNotAffectPropagationOrOperationalMetadata(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
            'sentry.traces_sample_rate' => 1.0,
        ]);
        Queue::createPayloadUsing(null);
        $integration = new QueueIntegration($this->app);
        $integration->onBoot($this->createMock(Dispatcher::class));
        $transaction = $this->startTransaction();
        $queue = (new QueueDataCollectionTestQueue())->setConnectionName('redis');

        $queue->applyPayloadHooks('default', ['uuid' => 'first', 'data' => ['name' => 'first']]);
        $this->assertSame('first', SentrySdk::getCurrentHub()->getSpan()->getData()['messaging.message.body.data']['name']);

        SentrySdk::getCurrentHub()->setSpan($transaction);
        $this->getSentryClientFromContainer()->getOptions()->getDataCollection()->setQueues(false);
        $transportPayload = $queue->applyPayloadHooks('default', ['uuid' => 'second', 'data' => ['name' => 'second']]);
        $spanData = SentrySdk::getCurrentHub()->getSpan()->getData();

        $this->assertArrayNotHasKey('messaging.message.body.data', $spanData);
        $this->assertSame('second', $spanData['messaging.message.id']);
        $this->assertSame('redis', $spanData['messaging.destination.connection']);
        $this->assertArrayHasKey('sentry_baggage_data', $transportPayload);
        $this->assertArrayHasKey('sentry_trace_parent_data', $transportPayload);
        $this->assertArrayHasKey('sentry_publish_time', $transportPayload);
        $this->assertSame(['name' => 'second'], $transportPayload['data']);
    }

    public function testBreadcrumbCollectionWorksWithoutTracingAndUnsampledParents(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
            'sentry.tracing.queue_jobs' => false,
            'sentry.tracing.queue_job_transactions' => false,
        ]);
        $integration = new QueueIntegration($this->app);
        $payload = ['data' => ['name' => 'breadcrumb-only']];

        $integration->handleJobProcessingQueueEvent(new JobProcessing('sync', $this->job($payload)));

        $this->assertSame('breadcrumb-only', $this->getLastSentryBreadcrumb()->getMetadata()['messaging.message.body.data']['name']);
        $this->assertNull(SentrySdk::getCurrentHub()->getSpan());

        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
            'sentry.traces_sample_rate' => 1.0,
        ]);
        $transaction = $this->startTransaction();
        $transaction->setSampled(false);
        $integration = new QueueIntegration($this->app);
        $integration->handleJobProcessingQueueEvent(new JobProcessing('sync', $this->job(['data' => ['name' => 'unsampled']])));

        $this->assertSame('unsampled', $this->getLastSentryBreadcrumb()->getMetadata()['messaging.message.body.data']['name']);
        $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan());
    }

    public function testSqsQueueNamesAreNormalized(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
            'sentry.traces_sample_rate' => 1.0,
        ]);
        $this->startTransaction();
        $integration = new QueueIntegration($this->app);

        $integration->handleJobProcessingQueueEvent(new JobProcessing('sqs', $this->job(
            ['data' => ['name' => 'queued']],
            'https://sqs.eu-west-1.amazonaws.com/123456789/example-queue'
        )));

        $this->assertSame('example-queue', SentrySdk::getCurrentHub()->getSpan()->getData()['messaging.destination.name']);
    }

    public function testStandaloneTransactionsCollectPayload(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
            'sentry.traces_sample_rate' => 1.0,
        ]);
        SentrySdk::getCurrentHub()->setSpan(null);
        $integration = new QueueIntegration($this->app);

        $integration->handleJobProcessingQueueEvent(new JobProcessing('database', $this->job([
            'uuid' => 'standalone-id',
            'data' => ['name' => 'standalone'],
        ], null)));

        $transaction = SentrySdk::getCurrentHub()->getSpan();
        $this->assertInstanceOf(Transaction::class, $transaction);
        $this->assertSame('standalone', $transaction->getData()['messaging.message.body.data']['name']);
        $this->assertSame('', $transaction->getData()['messaging.destination.name']);
        $this->assertSame('standalone-id', $transaction->getData()['messaging.message.id']);
    }

    public function testRuntimePolicyChangesApplyToSubsequentJobs(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
            'sentry.tracing.queue_jobs' => false,
            'sentry.tracing.queue_job_transactions' => false,
        ]);
        $integration = new QueueIntegration($this->app);
        $firstJob = $this->job(['data' => ['name' => 'first']]);
        $integration->handleJobProcessingQueueEvent(new JobProcessing('sync', $firstJob));
        $this->assertSame('first', $this->getLastSentryBreadcrumb()->getMetadata()['messaging.message.body.data']['name']);
        $integration->handleJobProcessedQueueEvent(new JobProcessed('sync', $firstJob));

        $this->getSentryClientFromContainer()->getOptions()->getDataCollection()->setQueues(false);
        $integration->handleJobProcessingQueueEvent(new JobProcessing('sync', $this->job(['data' => ['name' => 'second']])));

        $this->assertArrayNotHasKey('messaging.message.body.data', $this->getLastSentryBreadcrumb()->getMetadata());
    }

    public function testLegacyBreadcrumbOnlyHandlingObtainsPayloadWithoutCollectingIt(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => null,
            'sentry.tracing.queue_jobs' => false,
            'sentry.tracing.queue_job_transactions' => false,
        ]);
        $job = $this->createMock(Job::class);
        $job->expects($this->once())->method('payload')->willReturn(['data' => ['name' => 'Alice']]);
        $job->method('getName')->willReturn('App\\Jobs\\ExampleJob');
        $job->method('resolveName')->willReturn('App\\Jobs\\ExampleJob');
        $job->method('getQueue')->willReturn('default');
        $job->method('attempts')->willReturn(1);

        (new QueueIntegration($this->app))->handleJobProcessingQueueEvent(new JobProcessing('sync', $job));

        $breadcrumb = $this->getLastSentryBreadcrumb()->getMetadata();
        $this->assertSame('default', $breadcrumb['queue']);
        $this->assertArrayNotHasKey('messaging.message.body.data', $breadcrumb);
    }

    public function testFullyDisabledQueueInstrumentationIsNotApplicable(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
            'sentry.breadcrumbs.queue_info' => false,
            'sentry.tracing.queue_jobs' => false,
            'sentry.tracing.queue_job_transactions' => false,
        ]);

        $this->assertFalse((new QueueIntegration($this->app))->isApplicable());
    }

    public static function sendDefaultPiiProvider(): iterable
    {
        yield 'PII disabled' => [false];
        yield 'PII enabled' => [true];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function job(array $payload, ?string $queue = 'default', int $attempts = 1, ?string $rawBody = null): Job
    {
        $job = $this->createMock(Job::class);
        $job->method('payload')->willReturn($payload);
        $job->method('getName')->willReturn('App\\Jobs\\ExampleJob');
        $job->method('resolveName')->willReturn('App\\Jobs\\ExampleJob');
        $job->method('getQueue')->willReturn($queue);
        $job->method('attempts')->willReturn($attempts);
        $job->method('getRawBody')->willReturn($rawBody ?? json_encode($payload));

        return $job;
    }
}

class QueueDataCollectionTestQueue extends Queue
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function applyPayloadHooks(?string $queue, array $payload): array
    {
        return $this->withCreatePayloadHooks($queue, $payload);
    }
}

class QueueDataCollectionThrowingSerializable implements \JsonSerializable
{
    public function jsonSerialize(): array
    {
        throw new \LogicException('Must not serialize');
    }

    public function __toString(): string
    {
        throw new \LogicException('Must not cast');
    }
}
