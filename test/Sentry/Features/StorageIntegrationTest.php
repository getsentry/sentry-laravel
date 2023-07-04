<?php

namespace Sentry\Laravel\Tests\Features;

use Illuminate\Support\Facades\Storage;
use Sentry\Laravel\Tests\TestCase;
use Sentry\Tracing\TransactionContext;

class StorageIntegrationTest extends TestCase
{
    public function testCacheBreadcrumbForWriteAndHitIsRecorded(): void
    {
        $originalDisksLocalConfig = config('filesystems.disks.local');
        $this->resetApplicationWithConfig([
            'filesystems.disks.local' => [
                'driver' => 'sentry',
                'original_driver' => $originalDisksLocalConfig['driver'],
                'root' => $originalDisksLocalConfig['root'],
            ],
        ]);

        $hub = $this->getHubFromContainer();

        $transaction = $hub->startTransaction(new TransactionContext());
        $transaction->initSpanRecorder();

        $this->getCurrentScope()->setSpan($transaction);

        Storage::put('foo', 'bar');

        $spans = $transaction->getSpanRecorder()->getSpans();
        $this->assertArrayHasKey(1, $spans);

        $span = $spans[1];
        $this->assertSame('storage.put', $span->getOp());
        $this->assertSame(['path' => 'foo'], $span->getData());

        Storage::assertExists('foo', 'bar');
    }
}
