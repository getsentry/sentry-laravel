<?php

namespace Sentry\Laravel\Util;

/**
 * @template T
 *
 * @internal
 */
class BoundedOrderedMap
{
    /**
     * @var int
     */
    private $capacity;

    /**
     * @var array<string, T>
     */
    private $items = [];

    /**
     * @var callable|null
     */
    private $evictionCallback;

    public function __construct(int $capacity, ?callable $evictionCallback = null)
    {
        if ($capacity <= 0) {
            throw new \InvalidArgumentException('BoundedOrderedMap capacity must be greater than 0.');
        }

        $this->capacity = $capacity;
        $this->evictionCallback = $evictionCallback;
    }

    /**
     * @param T $value
     */
    public function set(string $key, $value): void
    {
        if (array_key_exists($key, $this->items)) {
            $this->items[$key] = $value;

            return;
        }

        while (\count($this->items) >= $this->capacity) {
            $this->evictOldest();
        }

        $this->items[$key] = $value;
    }

    /**
     * @return T|null
     */
    public function get(string $key)
    {
        return array_key_exists($key, $this->items) ? $this->items[$key] : null;
    }

    /**
     * @return T|null
     */
    public function pull(string $key)
    {
        if (!array_key_exists($key, $this->items)) {
            return null;
        }

        $value = $this->items[$key];
        unset($this->items[$key]);

        return $value;
    }

    /**
     * @return \Generator<string, T>
     */
    public function newestFirst(): \Generator
    {
        foreach (array_reverse($this->items, true) as $key => $value) {
            yield (string) $key => $value;
        }
    }

    private function evictOldest(): void
    {
        reset($this->items);
        $oldestKey = key($this->items);

        if ($oldestKey === null) {
            return;
        }

        $oldest = $this->items[$oldestKey];
        unset($this->items[$oldestKey]);

        if ($this->evictionCallback !== null) {
            ($this->evictionCallback)($oldest);
        }
    }
}
