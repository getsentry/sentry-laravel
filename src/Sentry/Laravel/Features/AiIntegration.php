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
    private const FEATURE_KEY = 'ai';

    private const MAX_MESSAGE_SIZE = 20480; // 20KB

    /** @var array<string, string> Known provider class → gen_ai.system identifier */
    private const PROVIDER_SYSTEM_MAP = [
        'Laravel\Ai\Providers\OpenAi' => 'openai',
        'Laravel\Ai\Providers\Anthropic' => 'anthropic',
        'Laravel\Ai\Providers\Gemini' => 'gcp.gemini',
        'Laravel\Ai\Providers\Groq' => 'groq',
        'Laravel\Ai\Providers\Mistral' => 'mistral_ai',
        'Laravel\Ai\Providers\DeepSeek' => 'deepseek',
        'Laravel\Ai\Providers\Ollama' => 'ollama',
        'Laravel\Ai\Providers\Cohere' => 'cohere',
        'Laravel\Ai\Providers\XAi' => 'xai',
    ];

    /**
     * Per-agent-invocation state keyed by invocation ID.
     *
     * Each entry holds: span, parentSpan, meta, urlPrefix, activeChatSpan, chatSpans, toolSpans.
     *
     * @var array<string, array<string, mixed>>
     */
    private $invocations = [];

    /**
     * Per-tool-invocation state keyed by tool invocation ID.
     *
     * Each entry holds: span, parentSpan.
     *
     * @var array<string, array{span: Span, parentSpan: Span|null}>
     */
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
        $events->listen('Laravel\Ai\Events\InvokingTool', [$this, 'handleInvokingToolForTracing']);
        $events->listen('Laravel\Ai\Events\ToolInvoked', [$this, 'handleToolInvokedForTracing']);

        if (class_exists(RequestSending::class)) {
            $events->listen(RequestSending::class, [$this, 'handleHttpRequestSending']);
            $events->listen(ResponseReceived::class, [$this, 'handleHttpResponseReceived']);
            $events->listen(ConnectionFailed::class, [$this, 'handleHttpConnectionFailed']);
        }
    }

    // ---- Agent lifecycle event handlers ----

    /**
     * Handle the PromptingAgent event: start an invoke_agent span and record
     * the provider URL prefix for HTTP event matching.
     */
    public function handlePromptingAgentForTracing(object $event): void
    {
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        if ($parentSpan === null || !$parentSpan->getSampled()) {
            return;
        }

        $agentName = $this->resolveAgentName($event->prompt->agent);
        $model = $event->prompt->model ?? null;

        $data = [
            'gen_ai.operation.name' => 'invoke_agent',
            'gen_ai.agent.name' => $agentName,
        ];

        if ($model !== null) {
            $data['gen_ai.request.model'] = $model;
        }

        $system = $this->resolveProviderSystem($event->prompt->provider);
        if ($system !== null) {
            $data['gen_ai.system'] = $system;
        }

        $toolDefinitions = $this->resolveToolDefinitions($event->prompt->agent);
        if ($toolDefinitions !== null) {
            $data['gen_ai.tool.definitions'] = $toolDefinitions;
        }

        if ($this->shouldSendDefaultPii()) {
            $promptText = $event->prompt->prompt ?? null;
            if ($promptText !== null) {
                $data['gen_ai.input.messages'] = $this->truncateString(
                    json_encode([
                        [
                            'role' => 'user',
                            'parts' => [['type' => 'text', 'content' => $promptText]],
                        ],
                    ])
                );
            }

            $instructions = $this->resolveAgentInstructions($event->prompt->agent);
            if ($instructions !== null) {
                $data['gen_ai.system_instructions'] = $this->truncateString($instructions);
            }
        }

        $agentSpan = $parentSpan->startChild(
            SpanContext::make()
                ->setOp('gen_ai.invoke_agent')
                ->setData($data)
                ->setOrigin('auto.ai.laravel')
                ->setDescription("invoke_agent {$agentName}")
        );

        $this->invocations[$event->invocationId] = [
            'span' => $agentSpan,
            'parentSpan' => $parentSpan,
            'meta' => [
                'agent_name' => $agentName,
                'system' => $system,
                'model' => $model,
            ],
            'urlPrefix' => $this->resolveProviderUrlPrefix($event->prompt->provider),
            'activeChatSpan' => null,
            'chatSpans' => [],
            'toolSpans' => [],
        ];

        SentrySdk::getCurrentHub()->setSpan($agentSpan);
    }

    /**
     * Handle the AgentPrompted event: finish any open chat span, enrich chat spans
     * with step data, and finish the invoke_agent span.
     */
    public function handleAgentPromptedForTracing(object $event): void
    {
        $invocationId = $event->invocationId;

        if (!isset($this->invocations[$invocationId])) {
            return;
        }

        // Finish any still-open chat span (safety net)
        $this->finishActiveChatSpan($invocationId);

        $inv = $this->invocations[$invocationId];
        $agentSpan = $inv['span'];
        $parentSpan = $inv['parentSpan'];

        $conversationId = $event->response->conversationId ?? null;

        $this->enrichChatSpansWithStepData($invocationId, $event->response, $conversationId);
        $this->setConversationIdOnToolSpans($invocationId, $conversationId);

        $data = $agentSpan->getData();

        if ($conversationId !== null) {
            $data['gen_ai.conversation.id'] = $conversationId;
        }

        $responseModel = $event->response->meta->model ?? null;
        if ($responseModel !== null) {
            $data['gen_ai.response.model'] = $responseModel;
        }

        $responseProvider = $event->response->meta->provider ?? null;
        if ($responseProvider !== null && !isset($data['gen_ai.system'])) {
            $data['gen_ai.system'] = strtolower($responseProvider);
        }

        $usage = $event->response->usage ?? null;
        if ($usage !== null) {
            $this->setTokenUsage($data, $usage);
        }

        if ($this->shouldSendDefaultPii()) {
            $outputMessages = $this->buildOutputMessages($event->response);
            if (!empty($outputMessages)) {
                $data['gen_ai.output.messages'] = $this->truncateString(json_encode($outputMessages));
            }
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
     * Handle the InvokingTool event: start an execute_tool child span.
     */
    public function handleInvokingToolForTracing(object $event): void
    {
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        if ($parentSpan === null || !$parentSpan->getSampled()) {
            return;
        }

        $toolName = $this->resolveToolName($event->tool);
        $agentName = $this->resolveAgentName($event->agent);

        $data = [
            'gen_ai.operation.name' => 'execute_tool',
            'gen_ai.tool.name' => $toolName,
            'gen_ai.tool.type' => 'function',
            'gen_ai.agent.name' => $agentName,
        ];

        $toolDescription = $this->resolveToolDescription($event->tool);
        if ($toolDescription !== null) {
            $data['gen_ai.tool.description'] = $toolDescription;
        }

        if ($this->shouldSendDefaultPii() && !empty($event->arguments)) {
            $data['gen_ai.tool.call.arguments'] = $this->truncateString(
                json_encode($event->arguments)
            );
        }

        $span = $parentSpan->startChild(
            SpanContext::make()
                ->setOp('gen_ai.execute_tool')
                ->setData($data)
                ->setOrigin('auto.ai.laravel')
                ->setDescription("execute_tool {$toolName}")
        );

        $this->toolInvocations[$event->toolInvocationId] = [
            'span' => $span,
            'parentSpan' => $parentSpan,
        ];

        // Track tool span in the parent invocation for retroactive enrichment
        if (isset($this->invocations[$event->invocationId])) {
            $this->invocations[$event->invocationId]['toolSpans'][] = $span;
        }

        SentrySdk::getCurrentHub()->setSpan($span);
    }

    /**
     * Handle the ToolInvoked event: finish the execute_tool span.
     */
    public function handleToolInvokedForTracing(object $event): void
    {
        $toolInvocationId = $event->toolInvocationId;

        if (!isset($this->toolInvocations[$toolInvocationId])) {
            return;
        }

        $inv = $this->toolInvocations[$toolInvocationId];
        unset($this->toolInvocations[$toolInvocationId]);

        $span = $inv['span'];
        $data = $span->getData();

        if ($this->shouldSendDefaultPii() && $event->result !== null) {
            $resultString = is_string($event->result) ? $event->result : json_encode($event->result);
            $data['gen_ai.tool.call.result'] = $this->truncateString($resultString);
        }

        $span->setData($data);
        $span->setStatus(SpanStatus::ok());
        $span->finish();

        if ($inv['parentSpan'] !== null) {
            SentrySdk::getCurrentHub()->setSpan($inv['parentSpan']);
        }
    }

    // ---- HTTP client event handlers ----

    /**
     * Handle HTTP RequestSending: if the URL matches an active agent invocation's
     * provider, start a gen_ai.chat span.
     */
    public function handleHttpRequestSending(RequestSending $event): void
    {
        $invocationId = $this->findMatchingInvocation($event->request->url());

        if ($invocationId === null || !isset($this->invocations[$invocationId])) {
            return;
        }

        $inv = &$this->invocations[$invocationId];
        $meta = $inv['meta'];
        $model = $meta['model'] ?? 'unknown';

        $data = [
            'gen_ai.operation.name' => 'chat',
        ];

        if ($model !== null && $model !== 'unknown') {
            $data['gen_ai.request.model'] = $model;
        }

        if (($meta['agent_name'] ?? null) !== null) {
            $data['gen_ai.agent.name'] = $meta['agent_name'];
        }

        if (($meta['system'] ?? null) !== null) {
            $data['gen_ai.system'] = $meta['system'];
        }

        $chatSpan = $inv['span']->startChild(
            SpanContext::make()
                ->setOp('gen_ai.chat')
                ->setData($data)
                ->setOrigin('auto.ai.laravel')
                ->setDescription("chat {$model}")
        );

        $inv['activeChatSpan'] = $chatSpan;
        $inv['chatSpans'][] = $chatSpan;

        SentrySdk::getCurrentHub()->setSpan($chatSpan);
    }

    public function handleHttpResponseReceived(ResponseReceived $event): void
    {
        $invocationId = $this->findMatchingInvocation($event->request->url());

        if ($invocationId !== null) {
            $this->finishActiveChatSpan($invocationId);
        }
    }

    public function handleHttpConnectionFailed(ConnectionFailed $event): void
    {
        $invocationId = $this->findMatchingInvocation($event->request->url());

        if ($invocationId !== null) {
            $this->finishActiveChatSpan($invocationId, SpanStatus::internalError());
        }
    }

    // ---- Chat span helpers ----

    /**
     * Find which active invocation matches the given URL by provider URL prefix.
     */
    private function findMatchingInvocation(string $url): ?string
    {
        foreach ($this->invocations as $invocationId => $inv) {
            if ($inv['urlPrefix'] !== null && str_starts_with($url, $inv['urlPrefix'])) {
                return $invocationId;
            }
        }

        return null;
    }

    private function finishActiveChatSpan(string $invocationId, ?SpanStatus $status = null): void
    {
        if (!isset($this->invocations[$invocationId])) {
            return;
        }

        $inv = &$this->invocations[$invocationId];
        $chatSpan = $inv['activeChatSpan'];

        if ($chatSpan === null) {
            return;
        }

        $inv['activeChatSpan'] = null;

        $chatSpan->setStatus($status ?? SpanStatus::ok());
        $chatSpan->finish();

        SentrySdk::getCurrentHub()->setSpan($inv['span']);
    }

    /**
     * Enrich completed chat spans with data from response steps.
     *
     * The response steps array matches 1:1 with chat spans in order:
     * step[0] → chatSpan[0], step[1] → chatSpan[1], etc.
     */
    private function enrichChatSpansWithStepData(string $invocationId, object $response, ?string $conversationId = null): void
    {
        $chatSpans = $this->invocations[$invocationId]['chatSpans'] ?? [];

        if (empty($chatSpans)) {
            return;
        }

        $steps = $response->steps ?? null;

        // Use all() to preserve Step objects; toArray() would recursively convert them to arrays
        if (is_object($steps) && method_exists($steps, 'all')) {
            $steps = $steps->all();
        } elseif (is_object($steps) && method_exists($steps, 'toArray')) {
            $steps = $steps->toArray();
        }

        $stepsArray = is_array($steps) ? array_values($steps) : [];

        foreach ($chatSpans as $index => $chatSpan) {
            $data = $chatSpan->getData();

            if ($conversationId !== null) {
                $data['gen_ai.conversation.id'] = $conversationId;
            }

            if (!isset($stepsArray[$index])) {
                $chatSpan->setData($data);
                continue;
            }

            $step = $stepsArray[$index];

            $model = $this->flexGet($this->flexGet($step, 'meta'), 'model');

            if ($model !== null) {
                $data['gen_ai.request.model'] = $model;
                $data['gen_ai.response.model'] = $model;
                $chatSpan->setDescription("chat {$model}");
            }

            $stepUsage = $this->flexGet($step, 'usage');
            if ($stepUsage !== null) {
                $this->setTokenUsage($data, $stepUsage);
            }

            $finishReason = $this->flexGet($step, 'finishReason');
            if ($finishReason !== null) {
                $data['gen_ai.response.finish_reasons'] = is_object($finishReason) && property_exists($finishReason, 'value')
                    ? $finishReason->value
                    : (string)$finishReason;
            }

            if ($this->shouldSendDefaultPii()) {
                $outputMessages = $this->buildOutputMessages($step);
                if (!empty($outputMessages)) {
                    $data['gen_ai.output.messages'] = $this->truncateString(json_encode($outputMessages));
                }
            }

            $chatSpan->setData($data);
        }
    }

    /**
     * Set conversation ID on all tool spans for a given invocation.
     */
    private function setConversationIdOnToolSpans(string $invocationId, ?string $conversationId): void
    {
        if ($conversationId === null) {
            return;
        }

        $toolSpans = $this->invocations[$invocationId]['toolSpans'] ?? [];

        foreach ($toolSpans as $toolSpan) {
            $data = $toolSpan->getData();
            $data['gen_ai.conversation.id'] = $conversationId;
            $toolSpan->setData($data);
        }
    }

    private function resolveProviderUrlPrefix(object $provider): ?string
    {
        if (!method_exists($provider, 'driver')) {
            return null;
        }

        $url = config("prism.providers.{$provider->driver()}.url");

        return is_string($url) && $url !== '' ? $url : null;
    }

    // ---- Message building ----

    /**
     * Build output messages from a response or step object.
     *
     * Handles both top-level response objects and per-step objects, since they
     * share the same structural properties (text, toolCalls, toolResults).
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildOutputMessages(object|array $source): array
    {
        $messages = [];
        $parts = [];

        $text = $this->flexGet($source, 'text');
        if ($text !== null && $text !== '') {
            $parts[] = ['type' => 'text', 'content' => $text];
        }

        $toolCalls = $this->flexGet($source, 'toolCalls');
        if ($toolCalls !== null) {
            if (is_object($toolCalls) && method_exists($toolCalls, 'toArray')) {
                $toolCalls = $toolCalls->toArray();
            }

            if (is_array($toolCalls)) {
                foreach ($toolCalls as $toolCall) {
                    $parts[] = $this->buildToolCallPart($toolCall);
                }
            }
        }

        if (!empty($parts)) {
            $messages[] = ['role' => 'assistant', 'parts' => $parts];
        }

        $toolResults = $this->flexGet($source, 'toolResults');
        if ($toolResults !== null && is_array($toolResults)) {
            foreach ($toolResults as $toolResult) {
                $result = $this->flexGet($toolResult, 'result');
                if ($result === null) {
                    continue;
                }

                $resultContent = is_string($result) ? $result : json_encode($result);
                $resultPart = ['type' => 'tool_result', 'content' => $resultContent];

                $resultName = $this->flexGet($toolResult, 'name');
                if ($resultName !== null) {
                    $resultPart['name'] = $resultName;
                }

                $messages[] = ['role' => 'tool', 'parts' => [$resultPart]];
            }
        }

        return $messages;
    }

    private function buildToolCallPart(object|array $toolCall): array
    {
        $part = ['type' => 'tool_call'];

        $name = $this->flexGet($toolCall, 'name');
        if ($name !== null) {
            $part['name'] = $name;
        }

        $args = $this->flexGet($toolCall, 'arguments');
        if ($args !== null) {
            $part['arguments'] = is_string($args) ? $args : json_encode($args);
        }

        return $part;
    }

    // ---- Resolution helpers ----

    private function resolveToolDefinitions(object $agent): ?string
    {
        if (!method_exists($agent, 'tools')) {
            return null;
        }

        $tools = $agent->tools();
        if (empty($tools)) {
            return null;
        }

        $definitions = [];
        foreach ($tools as $tool) {
            $def = ['name' => $this->resolveToolName($tool)];

            $description = $this->resolveToolDescription($tool);
            if ($description !== null) {
                $def['description'] = $description;
            }

            $definitions[] = $def;
        }

        return !empty($definitions) ? json_encode($definitions) : null;
    }

    private function resolveAgentName(object $agent): string
    {
        return $this->shortClassName($agent);
    }

    private function resolveToolName(object $tool): string
    {
        if (method_exists($tool, 'name')) {
            $name = $tool->name();
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return $this->shortClassName($tool);
    }

    private function resolveToolDescription(object $tool): ?string
    {
        if (!method_exists($tool, 'description')) {
            return null;
        }

        $description = $tool->description();

        if (is_string($description) && $description !== '') {
            return $description;
        }

        if ($description instanceof \Stringable) {
            return (string)$description;
        }

        return null;
    }

    private function resolveProviderSystem(object $provider): ?string
    {
        $className = get_class($provider);

        if (isset(self::PROVIDER_SYSTEM_MAP[$className])) {
            return self::PROVIDER_SYSTEM_MAP[$className];
        }

        foreach (self::PROVIDER_SYSTEM_MAP as $providerClass => $system) {
            if (is_a($className, $providerClass, true)) {
                return $system;
            }
        }

        return strtolower($this->shortClassName($provider));
    }

    private function resolveAgentInstructions(object $agent): ?string
    {
        if (!method_exists($agent, 'instructions')) {
            return null;
        }

        $instructions = $agent->instructions();

        return is_string($instructions) ? $instructions : (string)$instructions;
    }

    // ---- Data helpers ----

    /**
     * Set token usage data attributes on the span data array.
     *
     * @param array<string, mixed> $data
     * @param object|array $usage The Usage object (or array) from the Laravel AI SDK
     */
    private function setTokenUsage(array &$data, object|array $usage): void
    {
        $inputTokens = $this->flexGet($usage, 'promptTokens');
        $outputTokens = $this->flexGet($usage, 'completionTokens');

        if ($inputTokens !== null && $inputTokens > 0) {
            $data['gen_ai.usage.input_tokens'] = $inputTokens;
        }

        if ($outputTokens !== null && $outputTokens > 0) {
            $data['gen_ai.usage.output_tokens'] = $outputTokens;
        }

        if ($inputTokens !== null && $outputTokens !== null) {
            $totalTokens = $inputTokens + $outputTokens;
            if ($totalTokens > 0) {
                $data['gen_ai.usage.total_tokens'] = $totalTokens;
            }
        }

        $cachedTokens = $this->flexGet($usage, 'cacheReadInputTokens');
        if ($cachedTokens !== null && $cachedTokens > 0) {
            $data['gen_ai.usage.input_tokens.cached'] = $cachedTokens;
        }

        $cacheWriteTokens = $this->flexGet($usage, 'cacheWriteInputTokens');
        if ($cacheWriteTokens !== null && $cacheWriteTokens > 0) {
            $data['gen_ai.usage.input_tokens.cache_write'] = $cacheWriteTokens;
        }

        $reasoningTokens = $this->flexGet($usage, 'reasoningTokens');
        if ($reasoningTokens !== null && $reasoningTokens > 0) {
            $data['gen_ai.usage.output_tokens.reasoning'] = $reasoningTokens;
        }
    }

    /**
     * Access a property from a value that may be an object, array, or null.
     */
    private function flexGet(object|array|null $source, string $key): mixed
    {
        if ($source === null) {
            return null;
        }

        if (is_object($source)) {
            return $source->{$key} ?? null;
        }

        return $source[$key] ?? null;
    }

    private function shortClassName(object $obj): string
    {
        $parts = explode('\\', get_class($obj));

        return end($parts);
    }

    private function truncateString(string $value): string
    {
        if (strlen($value) <= self::MAX_MESSAGE_SIZE) {
            return $value;
        }

        return substr($value, 0, self::MAX_MESSAGE_SIZE) . '...(truncated)';
    }
}
