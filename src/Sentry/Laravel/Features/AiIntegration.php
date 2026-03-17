<?php

namespace Sentry\Laravel\Features;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
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

    /** @var array<string, array{span: Span, parentSpan: Span|null}> */
    private $toolInvocations = [];

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
        $events->listen('Laravel\Ai\Events\InvokingTool', [$this, 'handleInvokingToolForTracing']);
        $events->listen('Laravel\Ai\Events\ToolInvoked', [$this, 'handleToolInvokedForTracing']);

        if (class_exists(RequestSending::class)) {
            $events->listen(RequestSending::class, [$this, 'handleHttpRequestSending']);
            $events->listen(ResponseReceived::class, [$this, 'handleHttpResponseReceived']);
            $events->listen(ConnectionFailed::class, [$this, 'handleHttpConnectionFailed']);
        }
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

        $toolDefinitions = $this->resolveToolDefinitions($event->prompt->agent);
        if ($toolDefinitions !== null) {
            $data['gen_ai.tool.definitions'] = $toolDefinitions;
        }

        $agentSpan = $parentSpan->startChild(
            SpanContext::make()
                ->setOp('gen_ai.invoke_agent')
                ->setData($data)
                ->setOrigin('auto.ai.laravel')
                ->setDescription('invoke_agent ' . ($model ?? 'unknown'))
        );

        $this->evictOldestIfNeeded($this->invocations);

        $this->invocations[$event->invocationId] = new AiInvocationData(
            $agentSpan,
            $parentSpan,
            new AiInvocationMeta($agentName, $providerName, $model, $toolDefinitions),
            $provider !== null ? $this->resolveProviderUrlPrefix($provider) : null,
            $isStreaming
        );

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

        $this->finishActiveChatSpan($invocationId);
        $this->enrichChatSpansWithStepData($invocationId, $event->response);

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
            $this->setConversationIdOnSpans($invocationId, $conversationId);
        }

        $agentSpan->setData($data);
        $agentSpan->setStatus(SpanStatus::ok());
        $agentSpan->finish();

        unset($this->invocations[$invocationId]);

        if ($parentSpan !== null) {
            SentrySdk::getCurrentHub()->setSpan($parentSpan);
        }
    }

    public function handleHttpRequestSending(RequestSending $event): void
    {
        if (!$this->isTracingFeatureEnabled('gen_ai_chat')) {
            return;
        }

        $invocationId = $this->findMatchingInvocation($event->request->url());
        if ($invocationId === null || !isset($this->invocations[$invocationId])) {
            return;
        }

        $this->finishActiveChatSpan($invocationId);

        $invocation = $this->invocations[$invocationId];
        $meta = $invocation->meta;
        $model = $meta->model;

        $data = [
            'gen_ai.operation.name' => 'chat',
        ];

        if ($invocation->isStreaming) {
            $data['gen_ai.response.streaming'] = true;
        }

        if ($model !== null) {
            $data['gen_ai.request.model'] = $model;
        }

        if ($meta->agentName !== null) {
            $data['gen_ai.agent.name'] = $meta->agentName;
        }

        if ($meta->providerName !== null) {
            $data['gen_ai.provider.name'] = $meta->providerName;
        }

        if ($meta->toolDefinitions !== null) {
            $data['gen_ai.tool.definitions'] = $meta->toolDefinitions;
        }

        $chatSpan = $invocation->span->startChild(
            SpanContext::make()
                ->setOp('gen_ai.chat')
                ->setData($data)
                ->setOrigin('auto.ai.laravel')
                ->setDescription('chat ' . ($model ?? 'unknown'))
        );

        $invocation->activeChatSpan = $chatSpan;
        $invocation->chatSpans[] = $chatSpan;

        SentrySdk::getCurrentHub()->setSpan($chatSpan);
    }

    public function handleInvokingToolForTracing(\Laravel\Ai\Events\InvokingTool $event): void
    {
        if (!$this->isTracingFeatureEnabled('gen_ai_execute_tool')) {
            return;
        }

        $parentSpan = SentrySdk::getCurrentHub()->getSpan();
        if ($parentSpan === null || !$parentSpan->getSampled()) {
            return;
        }

        $toolDef = $this->resolveToolDefinition($event->tool);
        $agentName = $this->shortClassName($event->agent);

        $data = [
            'gen_ai.operation.name' => 'execute_tool',
            'gen_ai.tool.name' => $toolDef['name'],
            'gen_ai.tool.type' => $toolDef['type'],
            'gen_ai.agent.name' => $agentName,
        ];

        if (isset($toolDef['description'])) {
            $data['gen_ai.tool.description'] = $toolDef['description'];
        }

        if ($this->shouldSendDefaultPii() && !empty($event->arguments)) {
            $encoded = json_encode($event->arguments);
            if ($encoded !== false) {
                $data['gen_ai.tool.call.arguments'] = $encoded;
            }
        }

        $span = $parentSpan->startChild(
            SpanContext::make()
                ->setOp('gen_ai.execute_tool')
                ->setData($data)
                ->setOrigin('auto.ai.laravel')
                ->setDescription('execute_tool ' . $toolDef['name'])
        );

        $this->evictOldestIfNeeded($this->toolInvocations);
        $this->toolInvocations[$event->toolInvocationId] = [
            'span' => $span,
            'parentSpan' => $parentSpan,
        ];

        if (isset($this->invocations[$event->invocationId])) {
            $this->invocations[$event->invocationId]->toolSpans[] = $span;
        }

        SentrySdk::getCurrentHub()->setSpan($span);
    }

    public function handleToolInvokedForTracing(\Laravel\Ai\Events\ToolInvoked $event): void
    {
        $toolInvocationId = $event->toolInvocationId;
        if (!isset($this->toolInvocations[$toolInvocationId])) {
            return;
        }

        $invocation = $this->toolInvocations[$toolInvocationId];
        unset($this->toolInvocations[$toolInvocationId]);

        $span = $invocation['span'];
        $data = $span->getData();

        if ($this->shouldSendDefaultPii() && $event->result !== null) {
            $resultString = \is_string($event->result) ? $event->result : json_encode($event->result);
            if ($resultString !== false) {
                $data['gen_ai.tool.call.result'] = $resultString;
            }
        }

        $span->setData($data);
        $span->setStatus(SpanStatus::ok());
        $span->finish();

        if ($invocation['parentSpan'] !== null) {
            SentrySdk::getCurrentHub()->setSpan($invocation['parentSpan']);
        }
    }

    public function handleHttpResponseReceived(ResponseReceived $event): void
    {
        $invocationId = $this->findMatchingInvocation($event->request->url());
        if ($invocationId !== null) {
            $status = SpanStatus::createFromHttpStatusCode($event->response->status());
            $this->finishActiveChatSpan($invocationId, $status);
        }
    }

    public function handleHttpConnectionFailed(ConnectionFailed $event): void
    {
        $invocationId = $this->findMatchingInvocation($event->request->url());
        if ($invocationId !== null) {
            $this->finishActiveChatSpan($invocationId, SpanStatus::internalError());
        }
    }

    private function findMatchingInvocation(string $url): ?string
    {
        foreach (array_reverse($this->invocations, true) as $invocationId => $invocation) {
            if ($invocation->urlPrefix !== null && substr($url, 0, \strlen($invocation->urlPrefix)) === $invocation->urlPrefix) {
                return $invocationId;
            }
        }

        return null;
    }

    private function finishActiveChatSpan(string $invocationId, ?SpanStatus $status = null): void
    {
        $invocation = $this->invocations[$invocationId];
        if ($invocation->activeChatSpan === null) {
            return;
        }

        $invocation->activeChatSpan->setStatus($status ?? SpanStatus::ok());
        $invocation->activeChatSpan->finish();
        $invocation->activeChatSpan = null;

        SentrySdk::getCurrentHub()->setSpan($invocation->span);
    }

    private function enrichChatSpansWithStepData(string $invocationId, \Laravel\Ai\Responses\AgentResponse $response): void
    {
        $chatSpans = $this->invocations[$invocationId]->chatSpans;
        if (empty($chatSpans)) {
            return;
        }

        $steps = $response->steps ?? [];

        foreach ($chatSpans as $index => $chatSpan) {
            $data = $chatSpan->getData();
            $step = $steps[$index] ?? null;

            if ($step !== null) {
                $model = $step->meta->model ?? null;
                $usage = $step->usage ?? null;
                $finishReason = $step->finishReason ?? null;
            } else {
                $model = $response->meta->model ?? null;
                $usage = \count($chatSpans) === 1 ? $response->usage ?? null : null;
                $finishReason = null;
            }

            if ($model !== null) {
                $data['gen_ai.response.model'] = $model;
            }

            if ($usage !== null) {
                $this->setTokenUsage($data, $usage);
            }

            if ($finishReason !== null) {
                $data['gen_ai.response.finish_reasons'] = $finishReason->value;
            }

            $chatSpan->setData($data);
        }
    }

    private function setConversationIdOnSpans(string $invocationId, string $conversationId): void
    {
        $invocation = $this->invocations[$invocationId];
        $spans = [$invocation->span];

        foreach ($invocation->toolSpans as $toolSpan) {
            $spans[] = $toolSpan;
        }

        foreach ($invocation->chatSpans as $chatSpan) {
            $spans[] = $chatSpan;
        }

        foreach ($spans as $span) {
            $data = $span->getData();
            $data['gen_ai.conversation.id'] = $conversationId;
            $span->setData($data);
        }
    }

    private function resolveProviderUrlPrefix(\Laravel\Ai\Providers\Provider $provider): ?string
    {
        $url = config("prism.providers.{$provider->driver()}.url");

        return \is_string($url) && $url !== '' ? $url : null;
    }

    private function resolveToolDefinitions(\Laravel\Ai\Contracts\Agent $agent): ?string
    {
        if (!$agent instanceof \Laravel\Ai\Contracts\HasTools) {
            return null;
        }

        $tools = $agent->tools();
        if (empty($tools)) {
            return null;
        }

        $definitions = [];
        foreach ($tools as $tool) {
            $definitions[] = $this->resolveToolDefinition($tool);
        }

        $encoded = json_encode($definitions);

        return $encoded !== false ? $encoded : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveToolDefinition(\Laravel\Ai\Contracts\Tool $tool): array
    {
        $name = method_exists($tool, 'name') ? $tool->name() : null;

        $definition = [
            'type' => 'function',
            'name' => \is_string($name) && $name !== '' ? $name : $this->shortClassName($tool),
        ];

        $description = (string) $tool->description();
        if ($description !== '') {
            $definition['description'] = $description;
        }

        if (method_exists($tool, 'schema') && class_exists('Illuminate\JsonSchema\JsonSchemaTypeFactory')) {
            try {
                $factory = new \Illuminate\JsonSchema\JsonSchemaTypeFactory();
                $properties = $tool->schema($factory);
                if (!empty($properties) && \is_array($properties)) {
                    $objectType = new \Illuminate\JsonSchema\Types\ObjectType($properties);
                    $definition['parameters'] = $objectType->toArray();
                }
            } catch (\Throwable $e) {
                // Ignore schema resolution failures.
            }
        }

        return $definition;
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
                if ($oldest->activeChatSpan !== null) {
                    $oldest->activeChatSpan->setStatus(SpanStatus::deadlineExceeded());
                    $oldest->activeChatSpan->finish();
                }

                $oldest->span->setStatus(SpanStatus::deadlineExceeded());
                $oldest->span->finish();
            } elseif (isset($oldest['span']) && $oldest['span'] instanceof Span) {
                $oldest['span']->setStatus(SpanStatus::deadlineExceeded());
                $oldest['span']->finish();
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

    /** @var AiInvocationMeta */
    public $meta;

    /** @var string|null */
    public $urlPrefix;

    /** @var bool */
    public $isStreaming;

    /** @var Span|null */
    public $activeChatSpan;

    /** @var list<Span> */
    public $chatSpans = [];

    /** @var list<Span> */
    public $toolSpans = [];

    public function __construct(Span $span, ?Span $parentSpan, AiInvocationMeta $meta, ?string $urlPrefix, bool $isStreaming)
    {
        $this->span = $span;
        $this->parentSpan = $parentSpan;
        $this->meta = $meta;
        $this->urlPrefix = $urlPrefix;
        $this->isStreaming = $isStreaming;
        $this->activeChatSpan = null;
    }
}

class AiInvocationMeta
{
    /** @var string */
    public $agentName;

    /** @var string|null */
    public $providerName;

    /** @var string|null */
    public $model;

    /** @var string|null */
    public $toolDefinitions;

    public function __construct(string $agentName, ?string $providerName, ?string $model, ?string $toolDefinitions)
    {
        $this->agentName = $agentName;
        $this->providerName = $providerName;
        $this->model = $model;
        $this->toolDefinitions = $toolDefinitions;
    }
}
