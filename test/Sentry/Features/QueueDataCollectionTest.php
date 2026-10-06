<?php

namespace Sentry\Laravel\Tests\Features;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Queue as BaseQueue;
use Illuminate\Support\Facades\Queue;
use Sentry\Laravel\Tests\TestCase;
use Sentry\Tracing\Transaction;

class QueueDataCollectionTest extends TestCase
{
    protected function resetApplicationWithConfig(array $config): void
    {
        // The payload callbacks are static, so we remove the one registered by the previous application
        BaseQueue::createPayloadUsing(null);

        parent::resetApplicationWithConfig($config);
    }

    public function testArrayPayloadIsCollected(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
        ]);

        $transaction = $this->pushJob(QueueDataCollectionTestHandler::class, ['user_id' => 1, 'password' => 'secret']);

        $this->assertSame('{"user_id":1,"password":"[Filtered]"}', $this->getSpanData($transaction, 'queue.publish')['messaging.message.body.data']);
        $this->assertSame('{"user_id":1,"password":"[Filtered]"}', $this->getSpanData($transaction, 'queue.process')['messaging.message.body.data']);
    }

    public function testSerializedJobIsNotCollected(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
        ]);

        $transaction = $this->pushJob(new QueueDataCollectionTestJob('secret'));

        $this->assertArrayNotHasKey('messaging.message.body.data', $this->getSpanData($transaction, 'queue.publish'));
        $this->assertArrayNotHasKey('messaging.message.body.data', $this->getSpanData($transaction, 'queue.process'));
    }

    public function testArrayPayloadIsNotCollectedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [
                'queues' => false,
            ],
        ]);

        $transaction = $this->pushJob(QueueDataCollectionTestHandler::class, ['user_id' => 1]);

        $this->assertArrayNotHasKey('messaging.message.body.data', $this->getSpanData($transaction, 'queue.publish'));
        $this->assertArrayNotHasKey('messaging.message.body.data', $this->getSpanData($transaction, 'queue.process'));
    }

    public function testArrayPayloadIsNotCollectedWithoutDataCollection(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
        ]);

        $transaction = $this->pushJob(QueueDataCollectionTestHandler::class, ['user_id' => 1]);

        $this->assertArrayNotHasKey('messaging.message.body.data', $this->getSpanData($transaction, 'queue.publish'));
        $this->assertArrayNotHasKey('messaging.message.body.data', $this->getSpanData($transaction, 'queue.process'));
    }

    /**
     * The sync queue creates both the publish and the process span.
     *
     * @param object|string $job
     */
    private function pushJob($job, array $data = []): Transaction
    {
        $transaction = $this->startTransaction();

        Queue::connection('sync')->push($job, $data);

        return $transaction;
    }

    private function getSpanData(Transaction $transaction, string $op): array
    {
        foreach ($transaction->getSpanRecorder()->getSpans() as $span) {
            if ($span->getOp() === $op) {
                return $span->getData();
            }
        }

        $this->fail("No span with the op {$op} was recorded.");
    }
}

class QueueDataCollectionTestJob implements ShouldQueue
{
    public $apiToken;

    public function __construct(string $apiToken)
    {
        $this->apiToken = $apiToken;
    }

    public function handle(): void
    {
    }
}

class QueueDataCollectionTestHandler
{
    public function fire($job, $data): void
    {
    }
}
