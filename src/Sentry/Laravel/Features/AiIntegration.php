<?php

namespace Sentry\Laravel\Features;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;

class AiIntegration extends Feature
{
    private const FEATURE_KEY = 'gen_ai';
    private const FEATURE_KEY_INVOKE_AGENT = 'gen_ai_invoke_agent';
    private const FEATURE_KEY_CHAT = 'gen_ai_chat';
    private const FEATURE_KEY_EXECUTE_TOOL = 'gen_ai_execute_tool';
    private const FEATURE_KEY_EMBEDDINGS = 'gen_ai_embeddings';

    /** Maximum total byte size for serialized message data (matches Python SDK). */
    private const MAX_MESSAGE_BYTES = 20000;

    /** Maximum character length for a single message's content string (matches Python SDK). */
    private const MAX_SINGLE_MESSAGE_CONTENT_CHARS = 10000;

    /** Placeholder for binary content that should not be sent to Sentry. */
    private const BLOB_SUBSTITUTE = '[Blob substitute]';

    /** Regex pattern to detect data URIs (e.g. data:image/png;base64,...). */
    private const DATA_URI_PATTERN = '/^data:([^;,]+)?(?:;([^,]*))?,/s';

    /** Regex pattern to detect standalone base64-encoded strings (100+ chars). */
    private const BASE64_PATTERN = '/^[A-Za-z0-9+\/]{100,}={0,2}$/';

    /** Maximum tracked invocations before evicting oldest (prevents memory leaks in long-running processes). */
    private const MAX_TRACKED_INVOCATIONS = 100;

    /** @var array<string, AiInvocationData> Per-agent-invocation state keyed by invocation ID. */
    private $invocations = [];

    /** @var array<string, array{span: Span, parentSpan: Span|null}> Per-tool-invocation state keyed by tool invocation ID. */
    private $toolInvocations = [];

    /** @var array<string, array{span: Span, parentSpan: Span|null}> Per-embeddings-invocation state keyed by invocation ID. */
    private $embeddingsInvocations = [];

    public function isApplicable(): bool
    {
        return $this->isTracingFeatureEnabled(self::FEATURE_KEY)
            && class_exists('Laravel\Ai\Events\PromptingAgent');
    }

    public function onBoot(Dispatcher $events): void
    {
        $events->listen('Laravel\Ai\Events\PromptingAgent', [$this, 'handlePromptingAgentForTracing']);
        $events->listen('Laravel\Ai\Events\AgentPrompted', [$this, 'handleAgentPromptedForTracing']);
        $events->listen('Laravel\Ai\Events\StreamingAgent', [$this, 'handlePromptingAgentForTracing']);
        $events->listen('Laravel\Ai\Events\AgentStreamed', [$this, 'handleAgentPromptedForTracing']);
        $events->listen('Laravel\Ai\Events\InvokingTool', [$this, 'handleInvokingToolForTracing']);
        $events->listen('Laravel\Ai\Events\ToolInvoked', [$this, 'handleToolInvokedForTracing']);
        $events->listen('Laravel\Ai\Events\GeneratingEmbeddings', [$this, 'handleGeneratingEmbeddingsForTracing']);
        $events->listen('Laravel\Ai\Events\EmbeddingsGenerated', [$this, 'handleEmbeddingsGeneratedForTracing']);

        if (class_exists(RequestSending::class)) {
            $events->listen(RequestSending::class, [$this, 'handleHttpRequestSending']);
            $events->listen(ResponseReceived::class, [$this, 'handleHttpResponseReceived']);
            $events->listen(ConnectionFailed::class, [$this, 'handleHttpConnectionFailed']);
        }
    }

    public function handlePromptingAgentForTracing(PromptingAgent $event): void
    {
        if (!$this->isTracingFeatureEnabled(self::FEATURE_KEY_INVOKE_AGENT)) {
            return;
        }

        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        if ($parentSpan === null || !$parentSpan->getSampled()) {
            return;
        }

        $agentName = class_basename($event->prompt->agent);
        $model = $event->prompt->model;
        $isStreaming = is_a($event, 'Laravel\Ai\Events\StreamingAgent');

        $data = [
            'gen_ai.operation.name' => 'invoke_agent',
            'gen_ai.agent.name' => $agentName,
            'gen_ai.request.model' => $model
        ];

        if ($isStreaming) {
            $data['gen_ai.response.streaming'] = true;
        }

        $provider = $event->prompt->provider;
        $providerName = is_a($provider, 'Laravel\Ai\Providers\Provider') ? $provider->name() : null;
        if (!empty($providerName)) {
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

        $attachments = $this->resolveAttachments($event->prompt);

        if ($this->shouldSendDefaultPii()) {
            $inputMessages = $this->buildUserInputMessageFromParts(
                $event->prompt->prompt,
                $attachments
            );
            if (!empty($inputMessages)) {
                $data['gen_ai.input.messages'] = $this->truncateMessages($inputMessages);
            }

            $instructions = (string) $event->prompt->agent->instructions();
            if (!empty($instructions)) {
                $data['gen_ai.system_instructions'] = $this->truncateString($instructions);
            }
        }

        $agentSpan = $parentSpan->startChild(
            SpanContext::make()
                ->setOp('gen_ai.invoke_agent')
                ->setData($data)
                ->setOrigin('auto.ai.laravel')
                ->setDescription('invoke_agent ' . $model)
        );

        $this->evictOldestIfNeeded($this->invocations);

        $this->invocations[$event->invocationId] = new AiInvocationData(
            $agentSpan,
            $parentSpan,
            new AiInvocationMeta(
                $agentName,
                $providerName,
                $model,
                $event->prompt->prompt,
                $attachments,
                $toolDefinitions
            ),
            is_a($provider, 'Laravel\Ai\Providers\Provider') ? $this->resolveProviderUrlPrefix($provider) : null,
            $isStreaming
        );

        SentrySdk::getCurrentHub()->setSpan($agentSpan);
    }

    public function handleAgentPromptedForTracing(AgentPrompted $event): void
    {
        $invocationId = $event->invocationId;

        if (!isset($this->invocations[$invocationId])) {
            return;
        }

        $this->finishActiveChatSpan($invocationId);

        $invocation = $this->invocations[$invocationId];
        $agentSpan = $invocation->span;
        $parentSpan = $invocation->parentSpan;

        $conversationId = $event->response->conversationId;

        $this->enrichChatSpansWithStepData($invocationId, $event->response);
        $this->setConversationIdOnSpans($invocationId, $conversationId);

        $data = $agentSpan->getData();

        $responseModel = $event->response->meta->model;
        if ($responseModel !== null) {
            $data['gen_ai.response.model'] = $responseModel;
        }

        $responseProvider = $event->response->meta->provider;
        if ($responseProvider !== null && !isset($data['gen_ai.provider.name'])) {
            $data['gen_ai.provider.name'] = $responseProvider;
        }

        $this->setTokenUsage($data, $event->response->usage);
        if ($this->shouldSendDefaultPii()) {
            $outputMessages = $this->buildOutputMessages($event->response);
            if (!empty($outputMessages)) {
                $data['gen_ai.output.messages'] = $this->truncateMessages($outputMessages);
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

    public function handleInvokingToolForTracing(InvokingTool $event): void
    {
        if (!$this->isTracingFeatureEnabled(self::FEATURE_KEY_EXECUTE_TOOL)) {
            return;
        }

        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        if ($parentSpan === null || !$parentSpan->getSampled()) {
            return;
        }

        $toolDef = $this->resolveToolDefinition($event->tool);
        $agentName = class_basename($event->agent);

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
                $data['gen_ai.tool.call.arguments'] = $this->truncateString($encoded);
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

    public function handleToolInvokedForTracing(ToolInvoked $event): void
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
                $data['gen_ai.tool.call.result'] = $this->truncateString($resultString);
            }
        }

        $span->setData($data);
        $span->setStatus(SpanStatus::ok());
        $span->finish();

        if ($invocation['parentSpan'] !== null) {
            SentrySdk::getCurrentHub()->setSpan($invocation['parentSpan']);
        }
    }

    public function handleGeneratingEmbeddingsForTracing(GeneratingEmbeddings $event): void
    {
        if (!$this->isTracingFeatureEnabled(self::FEATURE_KEY_EMBEDDINGS)) {
            return;
        }

        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        if ($parentSpan === null || !$parentSpan->getSampled()) {
            return;
        }

        $data = [
            'gen_ai.operation.name' => 'embeddings',
            'gen_ai.request.model' => $event->model,
            'gen_ai.provider.name' => $event->provider->name(),
        ];

        if ($this->shouldSendDefaultPii() && !empty($event->prompt->inputs)) {
            $data['gen_ai.embeddings.input'] = $this->truncateEmbeddingInputs($event->prompt->inputs);
        }

        $span = $parentSpan->startChild(
            SpanContext::make()
                ->setOp('gen_ai.embeddings')
                ->setData($data)
                ->setOrigin('auto.ai.laravel')
                ->setDescription('embeddings ' . $event->model)
        );

        $this->evictOldestIfNeeded($this->embeddingsInvocations);

        $this->embeddingsInvocations[$event->invocationId] = [
            'span' => $span,
            'parentSpan' => $parentSpan,
        ];

        SentrySdk::getCurrentHub()->setSpan($span);
    }

    public function handleEmbeddingsGeneratedForTracing(EmbeddingsGenerated $event): void
    {
        $invocationId = $event->invocationId;

        if (!isset($this->embeddingsInvocations[$invocationId])) {
            return;
        }

        $invocation = $this->embeddingsInvocations[$invocationId];
        unset($this->embeddingsInvocations[$invocationId]);

        $span = $invocation['span'];
        $data = $span->getData();

        $responseModel = $event->response->meta->model;
        if ($responseModel !== null) {
            $data['gen_ai.response.model'] = $responseModel;
        }

        $responseProvider = $event->response->meta->provider;
        if ($responseProvider !== null && !isset($data['gen_ai.provider.name'])) {
            $data['gen_ai.provider.name'] = strtolower($responseProvider);
        }

        if ($event->response->tokens > 0) {
            $data['gen_ai.usage.input_tokens'] = $event->response->tokens;
        }

        $span->setData($data);
        $span->setStatus(SpanStatus::ok());
        $span->finish();

        if ($invocation['parentSpan'] !== null) {
            SentrySdk::getCurrentHub()->setSpan($invocation['parentSpan']);
        }
    }

    public function handleHttpRequestSending(RequestSending $event): void
    {
        if (!$this->isTracingFeatureEnabled(self::FEATURE_KEY_CHAT)) {
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
        $chatSpan = $invocation->activeChatSpan;
        if ($chatSpan === null) {
            return;
        }

        $invocation->activeChatSpan = null;

        $chatSpan->setStatus($status ?? SpanStatus::ok());
        $chatSpan->finish();

        SentrySdk::getCurrentHub()->setSpan($invocation->span);
    }

    /**
     * Enrich chat spans with step data. With steps (non-streaming), each step maps 1:1 to a chat span.
     * Without steps (streaming), response-level data is used instead.
     */
    private function enrichChatSpansWithStepData(string $invocationId, AgentResponse $response): void
    {
        $chatSpans = $this->invocations[$invocationId]->chatSpans;

        if (empty($chatSpans)) {
            return;
        }

        /**
         * @var $steps Step[]
         */
        $steps = $response->steps;

        foreach ($chatSpans as $index => $chatSpan) {
            $data = $chatSpan->getData();

            $step = $steps[$index] ?? null;

            if ($step !== null) {
                $model = $step->meta->model ?? null;
                $usage = $step->usage;
                $finishReason = $step->finishReason ?? null;
            } else {
                $model = $response->meta->model ?? null;
                $usage = \count($chatSpans) === 1 ? $response->usage : null;
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

            if ($this->shouldSendDefaultPii()) {
                if ($index === 0) {
                    $meta = $this->invocations[$invocationId]->meta;
                    $inputMessages = $this->buildUserInputMessageFromParts(
                        $meta->prompt,
                        $meta->attachments
                    );
                } elseif (\count($steps) > 0) {
                    $inputMessages = $this->buildChatInputMessages($steps, $index);
                } else {
                    // Streaming without steps: use response output as input for subsequent chat spans
                    $inputMessages = $this->buildOutputMessages($response);
                }

                if (!empty($inputMessages)) {
                    $data['gen_ai.input.messages'] = $this->truncateMessages($inputMessages);
                }

                $outputSource = $step ?? $response;

                $outputMessages = $this->buildOutputMessages($outputSource);
                if (!empty($outputMessages)) {
                    $data['gen_ai.output.messages'] = $this->truncateMessages($outputMessages);
                }
            }

            $chatSpan->setData($data);
        }
    }

    private function setConversationIdOnSpans(string $invocationId, ?string $conversationId): void
    {
        if ($conversationId !== null) {
            $invocation = $this->invocations[$invocationId];
            $spans = array_merge([$invocation->span], $invocation->toolSpans, $invocation->chatSpans);

            foreach ($spans as $span) {
                $data = $span->getData();
                $data['gen_ai.conversation.id'] = $conversationId;
                $span->setData($data);
            }
        }
    }

    private function resolveProviderUrlPrefix(\Laravel\Ai\Providers\Provider $provider): ?string
    {
        $url = config("ai.providers.{$provider->name()}.url")
            ?? config("prism.providers.{$provider->driver()}.url");

        return \is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveAttachments(AgentPrompt $prompt): array
    {
        $parts = [];
        foreach ($prompt->attachments as $attachment) {
            if (!\is_object($attachment)) {
                continue;
            }

            try {
                $parts[] = $this->transformAttachment($attachment);
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $parts;
    }

    /**
     * @return array<string, mixed>
     */
    private function transformAttachment(object $attachment): array
    {
        $name = method_exists($attachment, 'name') ? $attachment->name() : null;
        $mimeType = method_exists($attachment, 'mimeType')
            ? $attachment->mimeType()
            : null;

        if (is_a($attachment, 'Laravel\Ai\Files\RemoteImage')) {
            $modality = 'image';
            $type = 'uri';
            $content = $attachment->url ?? null;
        } elseif (is_a($attachment, 'Laravel\Ai\Files\RemoteDocument')) {
            $modality = 'document';
            $type = 'uri';
            $content = $attachment->url ?? null;
        } elseif (is_a($attachment, 'Laravel\Ai\Files\RemoteAudio')) {
            $modality = 'audio';
            $type = 'uri';
            $content = $attachment->url ?? null;
        } elseif (is_a($attachment, 'Laravel\Ai\Files\ProviderImage')) {
            $modality = 'image';
            $type = 'file_id';
            $content = $attachment->id ?? null;
        } elseif (is_a($attachment, 'Laravel\Ai\Files\ProviderDocument')) {
            $modality = 'document';
            $type = 'file_id';
            $content = $attachment->id ?? null;
        } else {
            $class = \get_class($attachment);
            if (is_a($attachment, 'Laravel\Ai\Files\Image', true)
                || strpos($class, 'Image') !== false) {
                $modality = 'image';
            } elseif (is_a($attachment, 'Laravel\Ai\Files\Document', true)
                || strpos($class, 'Document') !== false) {
                $modality = 'document';
            } elseif (is_a($attachment, 'Laravel\Ai\Files\Audio', true)
                || strpos($class, 'Audio') !== false) {
                $modality = 'audio';
            } else {
                $modality = 'file';
            }
            $type = 'blob';
            $content = self::BLOB_SUBSTITUTE;
        }

        $part = [
            'modality' => $modality,
            'type' => $type,
            'content' => $content,
        ];
        if ($name !== null) {
            $part['name'] = $name;
        }
        if ($mimeType !== null) {
            $part['mime_type'] = $mimeType;
        }

        return $part;
    }

    /**
     * @param array<int, array<string, mixed>> $attachmentParts
     * @return array<int, array<string, mixed>>
     */
    private function buildUserInputMessageFromParts(string $promptText, array $attachmentParts): array
    {
        $parts = $promptText !== ''
            ? [['type' => 'text', 'content' => $promptText]]
            : [];

        $parts = array_merge($parts, $attachmentParts);

        if (empty($parts)) {
            return [];
        }

        return [
            ['role' => 'user', 'parts' => $parts],
        ];
    }

    /**
     * @param array<int, Step>|Collection<int, Step> $steps
     * @return array<int, array<string, mixed>>
     */
    private function buildChatInputMessages($steps, int $index): array
    {
        $previousStep = $steps[$index - 1] ?? null;

        if ($previousStep === null) {
            return [];
        }

        return $this->buildOutputMessages($previousStep);
    }

    /**
     * Build output messages from a TextResponse or Step.
     *
     * @param TextResponse|Step $source
     * @return array<int, array<string, mixed>>
     */
    private function buildOutputMessages($source): array
    {
        $messages = [];
        $parts = [];

        $text = $source->text;
        if ($text !== '') {
            $parts[] = ['type' => 'text', 'content' => $text];
        }

        foreach ($source->toolCalls as $toolCall) {
            if (is_a($toolCall, 'Laravel\Ai\Responses\Data\ToolCall')) {
                $parts[] = $this->buildToolCallPart($toolCall);
            }
        }

        if (!empty($parts)) {
            $messages[] = ['role' => 'assistant', 'parts' => $parts];
        }

        foreach ($source->toolResults as $toolResult) {
            if (!is_a($toolResult, 'Laravel\Ai\Responses\Data\ToolResult')) {
                continue;
            }

            $result = $toolResult->result;
            if ($result === null) {
                continue;
            }

            $resultContent = \is_string($result) ? $result : json_encode($result);
            if ($resultContent === false) {
                continue;
            }
            $messages[] = [
                'role' => 'tool',
                'parts' => [[
                    'type' => 'tool_call_response',
                    'content' => $resultContent,
                    'id' => $toolResult->id,
                    'name' => $toolResult->name,
                ]]
            ];
        }

        return $messages;
    }

    /**
     * @param \Laravel\Ai\Responses\Data\ToolCall $toolCall
     */
    private function buildToolCallPart(object $toolCall): array
    {
        $part = [
            'type' => 'tool_call',
            'id' => $toolCall->id,
            'name' => $toolCall->name,
        ];

        $encoded = \json_encode($toolCall->arguments);
        if ($encoded !== false) {
            $part['arguments'] = $encoded;
        }

        return $part;
    }

    /**
     * @return int|float|string|null
     */
    private function resolveAgentAttribute(Agent $agent, string $attributeClass)
    {
        if (PHP_VERSION_ID < 80000 || !class_exists($attributeClass)) {
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

    private function resolveToolDefinitions(Agent $agent): ?string
    {
        if (!$agent instanceof HasTools) {
            return null;
        }

        $definitions = [];
        foreach ($agent->tools() as $tool) {
            if ($tool instanceof Tool) {
                $definitions[] = $this->resolveToolDefinition($tool);
            }
        }
        if (empty($definitions)) {
            return null;
        }

        $encoded = json_encode($definitions);

        return $encoded !== false ? $encoded : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveToolDefinition(Tool $tool): array
    {
        $name = method_exists($tool, 'name') ? $tool->name() : null;

        $definition = [
            'type' => 'function',
            'name' => \is_string($name) && $name !== '' ? $name : class_basename($tool),
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
     * @param array<string, mixed> $data
     */
    private function setTokenUsage(array &$data, Usage $usage): void
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

    private function truncateString(string $value, int $maxBytes = self::MAX_MESSAGE_BYTES): string
    {
        if (\strlen($value) <= $maxBytes) {
            return $value;
        }

        return substr($value, 0, $maxBytes) . '...(truncated)';
    }

    private function truncateContentString(string $value): string
    {
        if (mb_strlen($value) <= self::MAX_SINGLE_MESSAGE_CONTENT_CHARS) {
            return $value;
        }

        return mb_substr($value, 0, self::MAX_SINGLE_MESSAGE_CONTENT_CHARS) . '...';
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     */
    private function truncateMessages(array $messages): string
    {
        if (empty($messages)) {
            return '[]';
        }

        $messages = $this->redactBinaryInMessages($messages);

        foreach ($messages as &$message) {
            $message = $this->truncateMessageContent($message);
        }
        unset($message);

        if (\count($messages) === 1) {
            $encoded = json_encode($messages);

            if ($encoded === false) {
                return '[]';
            }

            return \strlen($encoded) <= self::MAX_MESSAGE_BYTES
                ? $encoded
                : $this->truncateString($encoded);
        }

        $encoded = json_encode($messages);

        if ($encoded !== false && \strlen($encoded) <= self::MAX_MESSAGE_BYTES) {
            return $encoded;
        }

        $lastMessage = end($messages);

        $encoded = json_encode([$lastMessage]);

        if ($encoded === false) {
            return '[]';
        }

        return $this->truncateString($encoded);
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private function truncateMessageContent(array $message): array
    {
        if (!isset($message['parts']) || !\is_array($message['parts'])) {
            return $message;
        }

        foreach ($message['parts'] as &$part) {
            if (isset($part['content']) && \is_string($part['content'])) {
                $part['content'] = $this->truncateContentString($part['content']);
            }
            if (isset($part['arguments']) && \is_string($part['arguments'])) {
                $part['arguments'] = $this->truncateContentString($part['arguments']);
            }
        }
        unset($part);

        return $message;
    }

    /**
     * @param array<int, mixed> $inputs
     */
    private function truncateEmbeddingInputs(array $inputs): string
    {
        if (empty($inputs)) {
            return '[]';
        }

        $kept = [];
        $totalBytes = 2;

        for ($i = 0, $count = \count($inputs); $i < $count; $i++) {
            $inputJson = json_encode($inputs[$i]);
            if ($inputJson === false) {
                continue;
            }
            $entryBytes = \strlen($inputJson) + (empty($kept) ? 0 : 1);

            if ($totalBytes + $entryBytes > self::MAX_MESSAGE_BYTES) {
                break;
            }

            $kept[] = $inputs[$i];
            $totalBytes += $entryBytes;
        }

        if (empty($kept)) {
            $firstInput = reset($inputs);
            if (\is_string($firstInput)) {
                $firstInput = $this->truncateContentString($firstInput);
            }

            $kept = [$firstInput];
        }

        $encoded = json_encode($kept);

        return $this->truncateString($encoded !== false ? $encoded : '[]');
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private function redactBinaryInMessages(array $messages): array
    {
        foreach ($messages as &$message) {
            if (!isset($message['parts']) || !\is_array($message['parts'])) {
                continue;
            }

            foreach ($message['parts'] as &$part) {
                $part = $this->redactContentPart($part);
            }
            unset($part);
        }
        unset($message);

        return $messages;
    }

    /**
     * @param array<string, mixed> $part
     * @return array<string, mixed>
     */
    private function redactContentPart(array $part): array
    {
        $type = $part['type'] ?? null;

        if ($type === 'blob') {
            $part['content'] = self::BLOB_SUBSTITUTE;
            return $part;
        }

        if ($type === 'image' || $type === 'image_url') {
            return $this->transformImagePart($part);
        }

        if (isset($part['content']) && \is_string($part['content'])) {
            $part['content'] = $this->redactBinaryInString($part['content']);
        }

        if (isset($part['data']) && \is_string($part['data'])) {
            if ($this->isBinaryString($part['data'])) {
                $part['data'] = self::BLOB_SUBSTITUTE;
            }
        }

        if (isset($part['source']) && \is_array($part['source'])) {
            $part['source'] = $this->redactSourceField($part['source']);
        }

        return $part;
    }

    /**
     * @param array<string, mixed> $part
     * @return array<string, mixed>
     */
    private function transformImagePart(array $part): array
    {
        if (isset($part['image_url']) && \is_array($part['image_url'])) {
            $url = $part['image_url']['url'] ?? '';
            if ($this->isDataUri($url)) {
                $metadata = $this->extractDataUriMetadata($url);
                $part['type'] = 'blob';
                $part['content'] = self::BLOB_SUBSTITUTE;
                if ($metadata['mime_type'] !== null) {
                    $part['mime_type'] = $metadata['mime_type'];
                }
                unset($part['image_url']);
            } else {
                $part['type'] = 'uri';
                $part['content'] = $url;
                unset($part['image_url']);
            }
            return $part;
        }

        if (isset($part['content']) && \is_string($part['content'])) {
            if ($this->isBinaryString($part['content'])) {
                $part['content'] = self::BLOB_SUBSTITUTE;
                $part['type'] = 'blob';
            }
        }

        if (isset($part['data']) && \is_string($part['data'])) {
            if ($this->isBinaryString($part['data'])) {
                $part['data'] = self::BLOB_SUBSTITUTE;
                $part['type'] = 'blob';
            }
        }

        if (isset($part['url']) && \is_string($part['url'])) {
            if ($this->isDataUri($part['url'])) {
                $metadata = $this->extractDataUriMetadata($part['url']);
                $part['type'] = 'blob';
                $part['content'] = self::BLOB_SUBSTITUTE;
                if ($metadata['mime_type'] !== null) {
                    $part['mime_type'] = $metadata['mime_type'];
                }
                unset($part['url']);
            } else {
                $part['type'] = 'uri';
                $part['content'] = $part['url'];
                unset($part['url']);
            }
        }

        return $part;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function redactSourceField(array $source): array
    {
        $sourceType = $source['type'] ?? null;

        if ($sourceType === 'base64') {
            $source['data'] = self::BLOB_SUBSTITUTE;
        }

        if (isset($source['data']) && \is_string($source['data']) && $this->isBinaryString($source['data'])) {
            $source['data'] = self::BLOB_SUBSTITUTE;
        }

        return $source;
    }

    private function redactBinaryInString(string $value): string
    {
        if ($this->isDataUri($value) || $this->isBase64String($value)) {
            return self::BLOB_SUBSTITUTE;
        }

        return $value;
    }

    private function isBinaryString(string $value): bool
    {
        return $this->isDataUri($value) || $this->isBase64String($value);
    }

    private function isDataUri(string $value): bool
    {
        return (bool) preg_match(self::DATA_URI_PATTERN, $value);
    }

    private function isBase64String(string $value): bool
    {
        return (bool) preg_match(self::BASE64_PATTERN, $value);
    }

    /**
     * @return array{mime_type: string|null, encoding: string|null}
     */
    private function extractDataUriMetadata(string $dataUri): array
    {
        if (!preg_match(self::DATA_URI_PATTERN, $dataUri, $matches)) {
            return ['mime_type' => null, 'encoding' => null];
        }

        return [
            'mime_type' => !empty($matches[1]) ? $matches[1] : null,
            'encoding' => !empty($matches[2]) ? $matches[2] : null,
        ];
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

    /** @var string */
    public $prompt;

    /** @var array */
    public $attachments;

    /** @var string|null */
    public $toolDefinitions;

    public function __construct(
        string $agentName,
        ?string $providerName,
        ?string $model,
        string $prompt,
        array $attachments,
        ?string $toolDefinitions
    ) {
        $this->agentName = $agentName;
        $this->providerName = $providerName;
        $this->model = $model;
        $this->prompt = $prompt;
        $this->attachments = $attachments;
        $this->toolDefinitions = $toolDefinitions;
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
    public $activeChatSpan = null;

    /** @var list<Span> */
    public $chatSpans = [];

    /** @var list<Span> */
    public $toolSpans = [];

    public function __construct(
        Span $span,
        ?Span $parentSpan,
        AiInvocationMeta $meta,
        ?string $urlPrefix,
        bool $isStreaming
    ) {
        $this->span = $span;
        $this->parentSpan = $parentSpan;
        $this->meta = $meta;
        $this->urlPrefix = $urlPrefix;
        $this->isStreaming = $isStreaming;
    }
}
