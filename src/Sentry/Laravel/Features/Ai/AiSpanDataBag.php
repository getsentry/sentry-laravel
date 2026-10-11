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


    public function setNonZero(string $key, ?int $value): void
    {
        if ($value !== null && $value !== 0) {
            $this->data[$key] = $value;
        }
    }

    public function setTokenUsage(?Usage $usage): void
    {
        if ($usage === null) {
            return;
        }
        // laravel/ai 1.0 renamed `promptTokens` / `completionTokens` to `inputTokens` / `outputTokens`
        // and moved the cache and reasoning counts onto the `TextUsage` subclass, where they are nullable
        $inputTokens = $usage->inputTokens ?? $usage->promptTokens ?? 0;
        $outputTokens = $usage->outputTokens ?? $usage->completionTokens ?? 0;

        $this->setNonZero('gen_ai.usage.input_tokens', $inputTokens);
        $this->setNonZero('gen_ai.usage.output_tokens', $outputTokens);
        $this->setNonZero('gen_ai.usage.total_tokens', $inputTokens + $outputTokens);
        $this->setNonZero('gen_ai.usage.input_tokens.cached', $usage->cacheReadInputTokens ?? null);
        $this->setNonZero('gen_ai.usage.input_tokens.cache_write', $usage->cacheWriteInputTokens ?? null);
        $this->setNonZero('gen_ai.usage.output_tokens.reasoning', $usage->reasoningTokens ?? null);
    }

    public function get(string $key)
    {
        return $this->data[$key] ?? null;
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
