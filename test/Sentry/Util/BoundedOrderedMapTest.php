<?php

namespace Sentry\Laravel\Tests\Util;

use PHPUnit\Framework\TestCase;
use Sentry\Laravel\Util\BoundedOrderedMap;

class BoundedOrderedMapTest extends TestCase
{
    public function testItStoresAndRetrievesValuesByStringKey(): void
    {
        $map = new BoundedOrderedMap(2);

        $map->set('first', 'alpha');
        $map->set('second', 'bravo');

        $this->assertSame('alpha', $map->get('first'));
        $this->assertNull($map->get('missing'));
    }

    public function testUpdatingExistingKeyKeepsOriginalOrder(): void
    {
        $evicted = [];
        $map = new BoundedOrderedMap(2, function (string $value) use (&$evicted): void {
            $evicted[] = $value;
        });

        $map->set('first', 'alpha');
        $map->set('second', 'bravo');
        $map->set('first', 'updated');
        $map->set('third', 'charlie');

        $this->assertNull($map->get('first'));
        $this->assertSame('bravo', $map->get('second'));
        $this->assertSame(['updated'], $evicted);
    }

    public function testItEvictsOldestEntryWhenCapacityIsExceeded(): void
    {
        $evicted = [];
        $map = new BoundedOrderedMap(2, function (string $value) use (&$evicted): void {
            $evicted[] = $value;
        });

        $map->set('first', 'alpha');
        $map->set('second', 'bravo');
        $map->set('third', 'charlie');

        $this->assertNull($map->get('first'));
        $this->assertSame('bravo', $map->get('second'));
        $this->assertSame('charlie', $map->get('third'));
        $this->assertSame(['alpha'], $evicted);
    }

    public function testPullRemovesEntryWithoutEvictionCallbackOrStaleCapacitySlot(): void
    {
        $evicted = [];
        $map = new BoundedOrderedMap(2, function (string $value) use (&$evicted): void {
            $evicted[] = $value;
        });

        $map->set('first', 'alpha');
        $map->set('second', 'bravo');

        $this->assertSame('alpha', $map->pull('first'));

        $map->set('third', 'charlie');

        $this->assertNull($map->get('first'));
        $this->assertSame('bravo', $map->get('second'));
        $this->assertSame('charlie', $map->get('third'));
        $this->assertSame([], $evicted);

        $map->set('fourth', 'delta');

        $this->assertNull($map->get('second'));
        $this->assertSame(['bravo'], $evicted);
    }

    public function testItCanIterateNewestFirst(): void
    {
        $map = new BoundedOrderedMap(3);

        $map->set('first', 'alpha');
        $map->set('second', 'bravo');
        $map->set('third', 'charlie');

        $this->assertSame([
            'third' => 'charlie',
            'second' => 'bravo',
            'first' => 'alpha',
        ], iterator_to_array($map->newestFirst()));
    }

    public function testCapacityMustBeGreaterThanZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('BoundedOrderedMap capacity must be greater than 0.');

        new BoundedOrderedMap(0);
    }
}
