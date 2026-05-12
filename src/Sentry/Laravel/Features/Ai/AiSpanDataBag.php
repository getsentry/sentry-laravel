<?php

namespace Sentry\Laravel\Features\Ai;

use Laravel\Ai\Responses\Data\Usage;

class AiSpanDataBag
{

    /**
     * @var array
     */
    private $data;
    
    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    public function set(string $key, $value): void
    {
        if ($value === null || $value === '' || $value === [] || $value === '[]' || $value === '{}') {
            return;
        }
        $this->data[$key] = $value;
    }

    public function setIfNotExists(string $key, $value): void
    {
        if (!isset($this->data[$key])) {
            $this->set($key, $value);
        }
    }


    public function setNonZero(string $key, int $value): void
    {
        if ($value !== 0) {
            $this->data[$key] = $value;
        }
    }

    public function setJsonEncoded(string $key, $value, int $maxLength): void
    {
        $encoded = \json_encode($value);
        if ($encoded !== false) {
            $this->setWithTruncation($key, $encoded, $maxLength);
        }
    }

    public function setWithTruncation(string $key, ?string $value, int $maxLength): void
    {
        $this->set($key, $this->truncateString($value, $maxLength));
    }

    public function contains(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function setTokenUsage(?Usage $usage): void
    {
        if ($usage === null) {
            return;
        }
        $this->setNonZero('gen_ai.usage.input_tokens', $usage->promptTokens);
        $this->setNonZero('gen_ai.usage.output_tokens', $usage->completionTokens);
        $this->setNonZero('gen_ai.usage.total_tokens', $usage->promptTokens + $usage->completionTokens);
        $this->setNonZero('gen_ai.usage.input_tokens.cached', $usage->cacheReadInputTokens);
        $this->setNonZero('gen_ai.usage.input_tokens.cache_write', $usage->cacheWriteInputTokens);
        $this->setNonZero('gen_ai.usage.output_tokens.reasoning', $usage->reasoningTokens);
    }

    public function get(string $key)
    {
        return $this->data[$key] ?? null;
    }

    public function toArray(): array
    {
        return $this->data;
    }

    private function truncateString(string $value, int $maxBytes): string
    {
        if (\strlen($value) <= $maxBytes) {
            return $value;
        }

        return substr($value, 0, $maxBytes) . '...(truncated)';
    }
}
