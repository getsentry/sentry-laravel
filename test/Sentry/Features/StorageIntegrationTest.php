<?php

namespace Sentry\Laravel\Tests\Features;

use Illuminate\Support\Facades\Storage;
use Sentry\Laravel\Tests\TestCase;
use Sentry\Tracing\TransactionContext;

class StorageIntegrationTest extends TestCase
{
    public function testCreatesSpanFor(): void
    {
        $hub = $this->getHubFromContainer();

        $transaction = $hub->startTransaction(new TransactionContext());
        $transaction->initSpanRecorder();

        $this->getCurrentScope()->setSpan($transaction);

        Storage::put('foo', 'bar');
        $fooContent = Storage::get('foo');
        Storage::assertExists('foo', 'bar');
        Storage::delete('foo');
        Storage::delete(['foo', 'bar']);

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertArrayHasKey(1, $spans);
        $span = $spans[1];
        $this->assertSame('file.put', $span->getOp());
        $this->assertSame('foo', $span->getDescription());
        $this->assertSame(['path' => 'foo', 'options' => [], 'disk' => 'local', 'driver' => 'local'], $span->getData());

        $this->assertArrayHasKey(2, $spans);
        $span = $spans[2];
        $this->assertSame('file.get', $span->getOp());
        $this->assertSame('foo', $span->getDescription());
        $this->assertSame(['path' => 'foo', 'disk' => 'local', 'driver' => 'local'], $span->getData());
        $this->assertSame('bar', $fooContent);

        $this->assertArrayHasKey(3, $spans);
        $span = $spans[3];
        $this->assertSame('file.assertExists', $span->getOp());
        $this->assertSame('foo', $span->getDescription());
        $this->assertSame(['path' => 'foo', 'disk' => 'local', 'driver' => 'local'], $span->getData());

        $this->assertArrayHasKey(4, $spans);
        $span = $spans[4];
        $this->assertSame('file.delete', $span->getOp());
        $this->assertSame('foo', $span->getDescription());
        $this->assertSame(['path' => 'foo', 'disk' => 'local', 'driver' => 'local'], $span->getData());

        $this->assertArrayHasKey(5, $spans);
        $span = $spans[5];
        $this->assertSame('file.delete', $span->getOp());
        $this->assertSame('2 paths', $span->getDescription());
        $this->assertSame(['paths' => ['foo', 'bar'], 'disk' => 'local', 'driver' => 'local'], $span->getData());
    }
}
