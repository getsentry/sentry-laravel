<?php

namespace Sentry\Laravel\Features;

use Illuminate\Contracts\Events\Dispatcher;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;

class AiIntegration extends Feature
{
    private const FEATURE_KEY = 'gen_ai';
    private const MAX_TRACKED_INVOCATIONS = 100;

    /** @var array<string, AiInvocationData> */
    private $invocations = [];

    public function isApplicable(): bool
    {
        if (!class_exists('Laravel\Ai\Events\PromptingAgent')) {
            return false;
        }

        return $this->isTracingFeatureEnabled(self::FEATURE_KEY);
    }

    public function onBoot(Dispatcher $events): void
    {
        $events->listen('Laravel\Ai\Events\PromptingAgent', [$this, 'handlePromptingAgentForTracing']);
        $events->listen('Laravel\Ai\Events\AgentPrompted', [$this, 'handleAgentPromptedForTracing']);
        $events->listen('Laravel\Ai\Events\StreamingAgent', [$this, 'handlePromptingAgentForTracing']);
        $events->listen('Laravel\Ai\Events\AgentStreamed', [$this, 'handleAgentPromptedForTracing']);
    }

    public function handlePromptingAgentForTracing(\Laravel\Ai\Events\PromptingAgent $event): void
    {
        if (!$this->isTracingFeatureEnabled('gen_ai_invoke_agent')) {
            return;
        }

        $parentSpan = SentrySdk::getCurrentHub()->getSpan();
        if ($parentSpan === null || !$parentSpan->getSampled()) {
            return;
        }

        $agentName = $this->shortClassName($event->prompt->agent);
        $model = $event->prompt->model ?? null;
        $isStreaming = is_a($event, 'Laravel\Ai\Events\StreamingAgent');

        $data = [
            'gen_ai.operation.name' => 'invoke_agent',
            'gen_ai.agent.name' => $agentName,
        ];

        if ($isStreaming) {
            $data['gen_ai.response.streaming'] = true;
        }

        if ($model !== null) {
            $data['gen_ai.request.model'] = $model;
        }

        $provider = $event->prompt->provider ?? null;
        $providerName = $provider !== null ? $provider->name() : null;
        if ($providerName !== null) {
            $data['gen_ai.provider.name'] = $providerName;
        }

        $temperature = $this->resolveAgentAttribute($event->prompt->agent, 'Laravel\Ai\Attributes\Temperature');
        if ($temperature !== null) {
            $data['gen_ai.request.temperature'] = $temperature;
        }

        $maxTokens = $this->resolveAgentAttribute($event->prompt->agent, 'Laravel\Ai\Attributes\MaxTokens');
        if ($maxTokens !== null) {
            $data['gen_ai.request.max_tokens'] = $maxTokens;
        }

        $agentSpan = $parentSpan->startChild(
            SpanContext::make()
                ->setOp('gen_ai.invoke_agent')
                ->setData($data)
                ->setOrigin('auto.ai.laravel')
                ->setDescription('invoke_agent ' . ($model ?? 'unknown'))
        );

        $this->evictOldestIfNeeded($this->invocations);

        $this->invocations[$event->invocationId] = new AiInvocationData($agentSpan, $parentSpan);
        SentrySdk::getCurrentHub()->setSpan($agentSpan);
    }

    public function handleAgentPromptedForTracing(\Laravel\Ai\Events\AgentPrompted $event): void
    {
        $invocationId = $event->invocationId;
        if (!isset($this->invocations[$invocationId])) {
            return;
        }

        $invocation = $this->invocations[$invocationId];
        $agentSpan = $invocation->span;
        $parentSpan = $invocation->parentSpan;

        $data = $agentSpan->getData();

        $responseModel = $event->response->meta->model ?? null;
        if ($responseModel !== null) {
            $data['gen_ai.response.model'] = $responseModel;
        }

        $responseProvider = $event->response->meta->provider ?? null;
        if ($responseProvider !== null && !isset($data['gen_ai.provider.name'])) {
            $data['gen_ai.provider.name'] = strtolower($responseProvider);
        }

        $usage = $event->response->usage ?? null;
        if ($usage !== null) {
            $this->setTokenUsage($data, $usage);
        }

        $conversationId = $event->response->conversationId ?? null;
        if ($conversationId !== null) {
            $data['gen_ai.conversation.id'] = $conversationId;
        }

        $agentSpan->setData($data);
        $agentSpan->setStatus(SpanStatus::ok());
        $agentSpan->finish();

        unset($this->invocations[$invocationId]);

        if ($parentSpan !== null) {
            SentrySdk::getCurrentHub()->setSpan($parentSpan);
        }
    }

    /**
     * @return int|float|string|null
     */
    private function resolveAgentAttribute(\Laravel\Ai\Contracts\Agent $agent, string $attributeClass)
    {
        if (!class_exists($attributeClass) || PHP_VERSION_ID < 80000) {
            return null;
        }

        try {
            $reflection = new \ReflectionClass($agent);
            $attributes = $reflection->getAttributes($attributeClass);
            if (empty($attributes)) {
                return null;
            }

            $instance = $attributes[0]->newInstance();
            return $instance->value ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function setTokenUsage(array &$data, \Laravel\Ai\Responses\Data\Usage $usage): void
    {
        $inputTokens = $usage->promptTokens ?? 0;
        if ($inputTokens > 0) {
            $data['gen_ai.usage.input_tokens'] = $inputTokens;
        }

        $outputTokens = $usage->completionTokens ?? 0;
        if ($outputTokens > 0) {
            $data['gen_ai.usage.output_tokens'] = $outputTokens;
        }

        $totalTokens = $inputTokens + $outputTokens;
        if ($totalTokens > 0) {
            $data['gen_ai.usage.total_tokens'] = $totalTokens;
        }

        $cachedTokens = $usage->cacheReadInputTokens ?? 0;
        if ($cachedTokens > 0) {
            $data['gen_ai.usage.input_tokens.cached'] = $cachedTokens;
        }

        $cacheWriteTokens = $usage->cacheWriteInputTokens ?? 0;
        if ($cacheWriteTokens > 0) {
            $data['gen_ai.usage.input_tokens.cache_write'] = $cacheWriteTokens;
        }

        $reasoningTokens = $usage->reasoningTokens ?? 0;
        if ($reasoningTokens > 0) {
            $data['gen_ai.usage.output_tokens.reasoning'] = $reasoningTokens;
        }
    }

    private function shortClassName(object $obj): string
    {
        $parts = explode('\\', \get_class($obj));

        return end($parts);
    }

    /**
     * @param array<string, mixed> $map
     */
    private function evictOldestIfNeeded(array &$map): void
    {
        while (\count($map) >= self::MAX_TRACKED_INVOCATIONS) {
            reset($map);
            $oldestKey = key($map);
            if ($oldestKey === null) {
                break;
            }

            $oldest = $map[$oldestKey];
            if ($oldest instanceof AiInvocationData) {
                $oldest->span->setStatus(SpanStatus::deadlineExceeded());
                $oldest->span->finish();
            }

            unset($map[$oldestKey]);
        }
    }
}

class AiInvocationData
{
    /** @var Span */
    public $span;

    /** @var Span|null */
    public $parentSpan;

    public function __construct(Span $span, ?Span $parentSpan)
    {
        $this->span = $span;
        $this->parentSpan = $parentSpan;
    }
}
