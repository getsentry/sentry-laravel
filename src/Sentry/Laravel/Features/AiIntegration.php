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

    /**
     * Maximum total byte size for serialized message data (20KB).
     * Matches Python SDK's MAX_GEN_AI_MESSAGE_BYTES.
     */
    private const MAX_MESSAGE_BYTES = 20_000;

    /**
     * Maximum character length for a single message's content string (10K chars).
     * Matches Python SDK's MAX_SINGLE_MESSAGE_CONTENT_CHARS.
     */
    private const MAX_SINGLE_MESSAGE_CONTENT_CHARS = 10_000;

    /**
     * Placeholder used to replace binary/blob content that should not be sent to Sentry.
     * Matches Python SDK's BLOB_DATA_SUBSTITUTE.
     */
    private const BLOB_SUBSTITUTE = '[Blob substitute]';

    /**
     * Regex pattern to detect data URIs with base64-encoded content.
     * Matches patterns like: data:image/png;base64,iVBORw0KGgo...
     */
    private const DATA_URI_PATTERN = '/^data:([^;,]+)?(?:;([^,]*))?,(.*)/s';

    /**
     * Regex pattern to detect standalone base64-encoded strings.
     * Matches strings that are at least 100 chars of valid base64 (likely binary data, not text).
     */
    private const BASE64_PATTERN = '/^[A-Za-z0-9+\/]{100,}={0,2}$/';

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

    /**
     * Per-embeddings-invocation state keyed by invocation ID.
     *
     * Each entry holds: span, parentSpan.
     *
     * @var array<string, array{span: Span, parentSpan: Span|null}>
     */
    private $embeddingsInvocations = [];

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
        $events->listen('Laravel\Ai\Events\GeneratingEmbeddings', [$this, 'handleGeneratingEmbeddingsForTracing']);
        $events->listen('Laravel\Ai\Events\EmbeddingsGenerated', [$this, 'handleEmbeddingsGeneratedForTracing']);

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

        $providerName = $event->prompt->provider->name();
        if ($providerName !== null) {
            $data['gen_ai.system'] = $providerName;
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

        if ($this->shouldSendDefaultPii()) {
            $inputMessages = $this->buildUserInputMessages($event->prompt);
            if (!empty($inputMessages)) {
                $data['gen_ai.input.messages'] = $this->truncateMessages($inputMessages);
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
                'system' => $providerName,
                'model' => $model,
                'prompt' => $event->prompt->prompt ?? null,
                'attachments' => $this->resolveAttachments($event->prompt),
                'toolDefinitions' => $toolDefinitions,
            ],
            'urlPrefix' => $this->resolveProviderUrlPrefix($event->prompt->provider),
            'isStreaming' => $isStreaming,
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

    // ---- Embeddings event handlers ----

    /**
     * Handle the GeneratingEmbeddings event: start a gen_ai.embeddings span.
     */
    public function handleGeneratingEmbeddingsForTracing(object $event): void
    {
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        if ($parentSpan === null || !$parentSpan->getSampled()) {
            return;
        }

        $model = $event->model ?? null;

        $data = [
            'gen_ai.operation.name' => 'embeddings',
        ];

        if ($model !== null) {
            $data['gen_ai.request.model'] = $model;
        }

        $providerName = method_exists($event->provider, 'name') ? $event->provider->name() : null;
        if ($providerName !== null) {
            $data['gen_ai.system'] = $providerName;
        }

        if ($this->shouldSendDefaultPii()) {
            $inputs = $event->prompt->inputs ?? null;
            if (is_array($inputs) && !empty($inputs)) {
                $data['gen_ai.embeddings.input'] = $this->truncateEmbeddingInputs($inputs);
            }
        }

        $span = $parentSpan->startChild(
            SpanContext::make()
                ->setOp('gen_ai.embeddings')
                ->setData($data)
                ->setOrigin('auto.ai.laravel')
                ->setDescription("embeddings {$model}")
        );

        $this->embeddingsInvocations[$event->invocationId] = [
            'span' => $span,
            'parentSpan' => $parentSpan,
        ];

        SentrySdk::getCurrentHub()->setSpan($span);
    }

    /**
     * Handle the EmbeddingsGenerated event: finish the gen_ai.embeddings span
     * and enrich with response data.
     */
    public function handleEmbeddingsGeneratedForTracing(object $event): void
    {
        $invocationId = $event->invocationId;

        if (!isset($this->embeddingsInvocations[$invocationId])) {
            return;
        }

        $inv = $this->embeddingsInvocations[$invocationId];
        unset($this->embeddingsInvocations[$invocationId]);

        $span = $inv['span'];
        $data = $span->getData();

        $responseModel = $event->response->meta->model ?? null;
        if ($responseModel !== null) {
            $data['gen_ai.response.model'] = $responseModel;
        }

        $responseProvider = $event->response->meta->provider ?? null;
        if ($responseProvider !== null && !isset($data['gen_ai.system'])) {
            $data['gen_ai.system'] = strtolower($responseProvider);
        }

        $tokens = $event->response->tokens ?? null;
        if ($tokens !== null && $tokens > 0) {
            $data['gen_ai.usage.input_tokens'] = $tokens;
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

        if ($inv['isStreaming'] ?? false) {
            $data['gen_ai.response.streaming'] = true;
        }

        if ($model !== null && $model !== 'unknown') {
            $data['gen_ai.request.model'] = $model;
        }

        if (($meta['agent_name'] ?? null) !== null) {
            $data['gen_ai.agent.name'] = $meta['agent_name'];
        }

        if (($meta['system'] ?? null) !== null) {
            $data['gen_ai.system'] = $meta['system'];
        }

        if (($meta['toolDefinitions'] ?? null) !== null) {
            $data['gen_ai.tool.definitions'] = $meta['toolDefinitions'];
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
     * When steps are available (non-streaming), each step maps 1:1 to a chat span
     * and provides per-step model, usage, finish reason, and messages.
     *
     * When steps are empty (streaming responses), the aggregate response-level data
     * is used instead: the response model is set on all chat spans, usage is only
     * attributed when there is a single chat span, the first chat span gets the
     * user prompt as input, and the last gets the response text as output.
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
        $hasSteps = !empty($stepsArray);
        $lastIndex = count($chatSpans) - 1;

        foreach ($chatSpans as $index => $chatSpan) {
            $data = $chatSpan->getData();

            if ($conversationId !== null) {
                $data['gen_ai.conversation.id'] = $conversationId;
            }

            // Resolve per-step data source, falling back to response-level data
            $step = $stepsArray[$index] ?? null;

            // Model: from step meta when available, otherwise from response meta
            $model = $step !== null
                ? $this->flexGet($this->flexGet($step, 'meta'), 'model')
                : $this->flexGet($this->flexGet($response, 'meta'), 'model');

            if ($model !== null) {
                $data['gen_ai.request.model'] = $model;
                $data['gen_ai.response.model'] = $model;
                $chatSpan->setDescription("chat {$model}");
            }

            // Usage: from step when available; from response only for single chat spans
            $usage = $step !== null
                ? $this->flexGet($step, 'usage')
                : (count($chatSpans) === 1 ? $this->flexGet($response, 'usage') : null);

            if ($usage !== null) {
                $this->setTokenUsage($data, $usage);
            }

            // Finish reason: only available from steps
            if ($step !== null) {
                $finishReason = $this->flexGet($step, 'finishReason');
                if ($finishReason !== null) {
                    $data['gen_ai.response.finish_reasons'] = is_object($finishReason) && property_exists($finishReason, 'value')
                        ? $finishReason->value
                        : (string)$finishReason;
                }
            }

            if ($this->shouldSendDefaultPii()) {
                // Input: with steps, buildChatInputMessages resolves per-step context;
                // without steps, only the first chat span gets the user prompt.
                if ($hasSteps) {
                    $inputMessages = $this->buildChatInputMessages($invocationId, $stepsArray, $index);
                } elseif ($index === 0) {
                    $inputMessages = $this->buildChatInputMessages($invocationId, [], 0);
                } else {
                    $inputMessages = [];
                }

                if (!empty($inputMessages)) {
                    $data['gen_ai.input.messages'] = $this->truncateMessages($inputMessages);
                }

                // Output: from step when available; without steps only the last
                // chat span gets the aggregate response output.
                $outputSource = $step ?? ($index === $lastIndex ? $response : null);

                if ($outputSource !== null) {
                    $outputMessages = $this->buildOutputMessages($outputSource);
                    if (!empty($outputMessages)) {
                        $data['gen_ai.output.messages'] = $this->truncateMessages($outputMessages);
                    }
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

    // ---- Attachment handling ----

    /**
     * Resolve attachments from a prompt object into an array of redacted content parts.
     *
     * The Laravel AI SDK's AgentPrompt has an `attachments` Collection containing
     * File subclass instances (Image, Document, Audio). We transform each into a
     * content part suitable for inclusion in gen_ai.input.messages, redacting any
     * binary data.
     *
     * @return array<int, array<string, mixed>>
     */
    private function resolveAttachments(object $prompt): array
    {
        try {
            $attachments = $prompt->attachments ?? null;
        } catch (\Throwable $e) {
            return [];
        }

        if ($attachments === null) {
            return [];
        }

        // Handle both Collection and array
        if (is_object($attachments) && method_exists($attachments, 'all')) {
            $attachments = $attachments->all();
        } elseif (is_object($attachments) && method_exists($attachments, 'toArray')) {
            $attachments = $attachments->toArray();
        }

        if (!is_array($attachments) || empty($attachments)) {
            return [];
        }

        $parts = [];
        foreach ($attachments as $attachment) {
            if (!is_object($attachment)) {
                continue;
            }

            try {
                $part = $this->transformAttachment($attachment);
                if ($part !== null) {
                    $parts[] = $part;
                }
            } catch (\Throwable $e) {
                // Skip individual attachments that fail to transform
                continue;
            }
        }

        return $parts;
    }

    /**
     * Transform a single attachment (File subclass) into a content part.
     *
     * File types and their transformations:
     * - LocalImage/StoredImage/Base64Image/LocalDocument/StoredDocument/Base64Document/LocalAudio/StoredAudio/Base64Audio
     *   → blob type with [Blob substitute], preserving mime_type and name metadata
     * - RemoteImage/RemoteDocument/RemoteAudio
     *   → uri type with the URL, preserving name metadata
     * - ProviderImage/ProviderDocument
     *   → file_id type with the provider ID, preserving name metadata
     *
     * @return array<string, mixed>|null
     */
    private function transformAttachment(object $attachment): ?array
    {
        // Determine the modality based on the class hierarchy
        $modality = $this->resolveAttachmentModality($attachment);

        // Check for Arrayable/toArray to inspect the type field
        $arrayForm = null;
        if (method_exists($attachment, 'toArray')) {
            $arrayForm = $attachment->toArray();
        }

        $type = $arrayForm['type'] ?? null;
        $name = method_exists($attachment, 'name') ? $attachment->name() : ($attachment->name ?? null);
        $mimeType = method_exists($attachment, 'mimeType') ? $this->safeCall(fn() => $attachment->mimeType()) : null;

        // Remote files (have a URL, no binary data to redact)
        if ($type === 'remote-image' || $type === 'remote-document' || $type === 'remote-audio') {
            $url = $attachment->url ?? ($arrayForm['url'] ?? null);
            $part = [
                'type' => 'uri',
                'modality' => $modality,
                'content' => $url,
            ];
            if ($name !== null) {
                $part['name'] = $name;
            }
            if ($mimeType !== null) {
                $part['mime_type'] = $mimeType;
            }
            return $part;
        }

        // Provider files (referenced by ID, no binary data)
        if ($type === 'provider-image' || $type === 'provider-document') {
            $id = $attachment->id ?? ($arrayForm['id'] ?? null);
            $part = [
                'type' => 'file_id',
                'modality' => $modality,
                'content' => $id,
            ];
            if ($name !== null) {
                $part['name'] = $name;
            }
            return $part;
        }

        // All other types (local, base64, stored) contain binary data → redact
        $part = [
            'type' => 'blob',
            'modality' => $modality,
            'content' => self::BLOB_SUBSTITUTE,
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
     * Determine the modality of an attachment based on its class name.
     */
    private function resolveAttachmentModality(object $attachment): string
    {
        $class = get_class($attachment);

        if (is_a($attachment, 'Laravel\Ai\Files\Image', true)
            || str_contains($class, 'Image')) {
            return 'image';
        }

        if (is_a($attachment, 'Laravel\Ai\Files\Document', true)
            || str_contains($class, 'Document')) {
            return 'document';
        }

        if (is_a($attachment, 'Laravel\Ai\Files\Audio', true)
            || str_contains($class, 'Audio')) {
            return 'audio';
        }

        return 'file';
    }

    /**
     * Build user input messages from a prompt, including text and attachments.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildUserInputMessages(object $prompt): array
    {
        $promptText = $prompt->prompt ?? null;
        $attachmentParts = $this->resolveAttachments($prompt);

        $parts = [];

        if ($promptText !== null && $promptText !== '') {
            $parts[] = ['type' => 'text', 'content' => $promptText];
        }

        foreach ($attachmentParts as $attachmentPart) {
            $parts[] = $attachmentPart;
        }

        if (empty($parts)) {
            return [];
        }

        return [
            ['role' => 'user', 'parts' => $parts],
        ];
    }

    /**
     * Build user input messages from stored meta data (for chat span enrichment).
     *
     * @param array<string, mixed> $meta
     * @return array<int, array<string, mixed>>
     */
    private function buildUserInputMessagesFromMeta(array $meta): array
    {
        $promptText = $meta['prompt'] ?? null;
        $attachmentParts = $meta['attachments'] ?? [];

        $parts = [];

        if ($promptText !== null && $promptText !== '') {
            $parts[] = ['type' => 'text', 'content' => $promptText];
        }

        foreach ($attachmentParts as $attachmentPart) {
            $parts[] = $attachmentPart;
        }

        if (empty($parts)) {
            return [];
        }

        return [
            ['role' => 'user', 'parts' => $parts],
        ];
    }

    /**
     * Safely call a closure, returning null on any exception.
     */
    private function safeCall(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ---- Message building ----

    /**
     * Build input messages for a chat span based on its position in the step sequence.
     *
     * For the first chat span (index 0), the input is the original user prompt.
     * For subsequent chat spans, the input is the previous step's output
     * (assistant tool calls + tool results sent back to the LLM).
     *
     * @param array<int, object|array> $stepsArray
     * @return array<int, array<string, mixed>>
     */
    private function buildChatInputMessages(string $invocationId, array $stepsArray, int $index): array
    {
        if ($index === 0) {
            return $this->buildUserInputMessagesFromMeta($this->invocations[$invocationId]['meta'] ?? []);
        }

        $previousStep = $stepsArray[$index - 1] ?? null;

        if ($previousStep === null) {
            return [];
        }

        return $this->buildOutputMessages($previousStep);
    }

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

    /**
     * Read a PHP attribute value from the agent class.
     *
     * Laravel AI SDK uses PHP 8 attributes like #[Temperature(0.7)], #[MaxTokens(4096)],
     * etc. to configure agents. Each attribute has a public $value property.
     *
     * @return int|float|string|null The attribute's value, or null if not present
     */
    private function resolveAgentAttribute(object $agent, string $attributeClass): mixed
    {
        if (!class_exists($attributeClass)) {
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
            $def = [
                'type' => 'function',
                'name' => $this->resolveToolName($tool),
            ];

            $description = $this->resolveToolDescription($tool);
            if ($description !== null) {
                $def['description'] = $description;
            }

            $parameters = $this->resolveToolParameters($tool);
            if ($parameters !== null) {
                $def['parameters'] = $parameters;
            }

            $definitions[] = $def;
        }

        return !empty($definitions) ? json_encode($definitions) : null;
    }

    /**
     * Resolve a tool's parameter schema by calling its schema() method.
     *
     * Uses Laravel's JsonSchema type system to serialize the tool's parameter
     * definitions into a standard JSON Schema object with type, properties,
     * required, etc.
     *
     * @return array<string, mixed>|null The JSON Schema array, or null if unavailable
     */
    private function resolveToolParameters(object $tool): ?array
    {
        if (!method_exists($tool, 'schema')) {
            return null;
        }

        if (!class_exists('Illuminate\JsonSchema\JsonSchemaTypeFactory')) {
            return null;
        }

        try {
            $factory = new \Illuminate\JsonSchema\JsonSchemaTypeFactory();
            $properties = $tool->schema($factory);

            if (empty($properties) || !is_array($properties)) {
                return null;
            }

            $objectType = new \Illuminate\JsonSchema\Types\ObjectType($properties);

            return $objectType->toArray();
        } catch (\Throwable $e) {
            return null;
        }
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

    // ---- Truncation and redaction ----

    /**
     * Truncate a string to fit within the byte limit.
     *
     * @param string $value The string to truncate
     * @param int $maxBytes Maximum byte size (defaults to MAX_MESSAGE_BYTES)
     */
    private function truncateString(string $value, int $maxBytes = self::MAX_MESSAGE_BYTES): string
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        return substr($value, 0, $maxBytes) . '...(truncated)';
    }

    /**
     * Truncate a content string to the single-message character limit.
     *
     * Used when capping individual message content within the message truncation pipeline.
     */
    private function truncateContentString(string $value): string
    {
        if (mb_strlen($value) <= self::MAX_SINGLE_MESSAGE_CONTENT_CHARS) {
            return $value;
        }

        return mb_substr($value, 0, self::MAX_SINGLE_MESSAGE_CONTENT_CHARS) . '...';
    }

    /**
     * Truncate and annotate a messages array following the Python SDK strategy:
     * - Keep only the last message
     * - Cap the last message's content at MAX_SINGLE_MESSAGE_CONTENT_CHARS
     * - If the final JSON still exceeds MAX_MESSAGE_BYTES, truncate the raw string
     *
     * @param array<int, array<string, mixed>> $messages
     */
    private function truncateMessages(array $messages): string
    {
        if (empty($messages)) {
            return '[]';
        }

        // First, redact binary content in all messages
        $messages = $this->redactBinaryInMessages($messages);

        // Always apply per-message content truncation (10K char limit)
        foreach ($messages as &$message) {
            $message = $this->truncateMessageContent($message);
        }
        unset($message);

        // Try encoding all messages first
        $encoded = json_encode($messages);

        if ($encoded !== false && strlen($encoded) <= self::MAX_MESSAGE_BYTES) {
            return $encoded;
        }

        // Keep only the last message (matches Python SDK behavior)
        $lastMessage = end($messages);

        $encoded = json_encode([$lastMessage]);

        if ($encoded === false) {
            return '[]';
        }

        // Final safety net: truncate the raw JSON string if still over budget
        return $this->truncateString($encoded);
    }

    /**
     * Truncate content strings within a single message's parts.
     *
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private function truncateMessageContent(array $message): array
    {
        if (!isset($message['parts']) || !is_array($message['parts'])) {
            return $message;
        }

        foreach ($message['parts'] as &$part) {
            if (isset($part['content']) && is_string($part['content'])) {
                $part['content'] = $this->truncateContentString($part['content']);
            }
            if (isset($part['arguments']) && is_string($part['arguments'])) {
                $part['arguments'] = $this->truncateContentString($part['arguments']);
            }
        }

        return $message;
    }

    /**
     * Truncate embedding inputs following the Python SDK strategy:
     * - Keep as many inputs as fit within MAX_MESSAGE_BYTES, working backward from the end
     * - If a single remaining input exceeds the limit, truncate its content
     *
     * @param array<int, mixed> $inputs
     */
    private function truncateEmbeddingInputs(array $inputs): string
    {
        if (empty($inputs)) {
            return '[]';
        }

        $kept = [];
        $totalBytes = 2; // Account for the JSON array brackets "[]"

        // Work backward from the end, keeping as many as fit
        for ($i = count($inputs) - 1; $i >= 0; $i--) {
            $inputJson = json_encode($inputs[$i]);
            $entryBytes = strlen($inputJson) + (empty($kept) ? 0 : 1); // +1 for comma separator

            if ($totalBytes + $entryBytes > self::MAX_MESSAGE_BYTES) {
                break;
            }

            array_unshift($kept, $inputs[$i]);
            $totalBytes += $entryBytes;
        }

        // If we couldn't keep any, take just the last one and truncate it
        if (empty($kept)) {
            $lastInput = end($inputs);
            if (is_string($lastInput)) {
                $lastInput = $this->truncateContentString($lastInput);
            }
            $kept = [$lastInput];
        }

        $encoded = json_encode($kept);

        // Final safety net
        return $this->truncateString($encoded !== false ? $encoded : '[]');
    }

    // ---- Binary content redaction ----

    /**
     * Redact binary content in all messages.
     *
     * Scans message parts for base64 data, data URIs, and binary content,
     * replacing them with the blob substitute while preserving metadata.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private function redactBinaryInMessages(array $messages): array
    {
        foreach ($messages as &$message) {
            if (!isset($message['parts']) || !is_array($message['parts'])) {
                continue;
            }

            foreach ($message['parts'] as &$part) {
                $part = $this->redactContentPart($part);
            }
        }

        return $messages;
    }

    /**
     * Redact binary content in a single content part.
     *
     * Handles:
     * - Parts with type 'blob' → replace content with blob substitute
     * - Parts with type 'image' or 'image_url' → transform and redact
     * - Text content containing data URIs → replace with blob substitute
     * - Text content that is pure base64 → replace with blob substitute
     *
     * @param array<string, mixed> $part
     * @return array<string, mixed>
     */
    private function redactContentPart(array $part): array
    {
        $type = $part['type'] ?? null;

        // Explicit blob type — always redact
        if ($type === 'blob') {
            $part['content'] = self::BLOB_SUBSTITUTE;
            return $part;
        }

        // Image or image_url types — redact binary data, keep metadata
        if ($type === 'image' || $type === 'image_url') {
            return $this->transformImagePart($part);
        }

        // For text and other types, check if content contains binary data
        if (isset($part['content']) && is_string($part['content'])) {
            $part['content'] = $this->redactBinaryInString($part['content']);
        }

        // Check data field (used by some providers for base64 content)
        if (isset($part['data']) && is_string($part['data'])) {
            if ($this->isBinaryString($part['data'])) {
                $part['data'] = self::BLOB_SUBSTITUTE;
            }
        }

        // Check source field (used by Anthropic-style content)
        if (isset($part['source']) && is_array($part['source'])) {
            $part['source'] = $this->redactSourceField($part['source']);
        }

        return $part;
    }

    /**
     * Transform an image/image_url content part: redact binary data, keep metadata.
     *
     * @param array<string, mixed> $part
     * @return array<string, mixed>
     */
    private function transformImagePart(array $part): array
    {
        // Handle image_url with nested url field (OpenAI style)
        if (isset($part['image_url']) && is_array($part['image_url'])) {
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
                // Regular URL — transform to uri type
                $part['type'] = 'uri';
                $part['content'] = $url;
                unset($part['image_url']);
            }
            return $part;
        }

        // Handle inline content/data (Google/generic style)
        if (isset($part['content']) && is_string($part['content'])) {
            if ($this->isBinaryString($part['content'])) {
                $part['content'] = self::BLOB_SUBSTITUTE;
                $part['type'] = 'blob';
            }
        }

        if (isset($part['data']) && is_string($part['data'])) {
            if ($this->isBinaryString($part['data'])) {
                $part['data'] = self::BLOB_SUBSTITUTE;
                $part['type'] = 'blob';
            }
        }

        // Handle URL field directly
        if (isset($part['url']) && is_string($part['url'])) {
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
     * Redact binary content in source fields (Anthropic style).
     *
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function redactSourceField(array $source): array
    {
        $sourceType = $source['type'] ?? null;

        if ($sourceType === 'base64') {
            $source['data'] = self::BLOB_SUBSTITUTE;
        }

        if (isset($source['data']) && is_string($source['data']) && $this->isBinaryString($source['data'])) {
            $source['data'] = self::BLOB_SUBSTITUTE;
        }

        return $source;
    }

    /**
     * Redact binary data in a string value.
     *
     * Replaces data URIs and standalone base64 strings with the blob substitute.
     */
    private function redactBinaryInString(string $value): string
    {
        // Check for data URI
        if ($this->isDataUri($value)) {
            return self::BLOB_SUBSTITUTE;
        }

        // Check for standalone base64
        if ($this->isBase64String($value)) {
            return self::BLOB_SUBSTITUTE;
        }

        return $value;
    }

    /**
     * Check if a string looks like binary data (data URI or base64).
     */
    private function isBinaryString(string $value): bool
    {
        return $this->isDataUri($value) || $this->isBase64String($value);
    }

    /**
     * Check if a string is a data URI.
     */
    private function isDataUri(string $value): bool
    {
        return (bool)preg_match(self::DATA_URI_PATTERN, $value);
    }

    /**
     * Check if a string looks like a standalone base64-encoded binary payload.
     */
    private function isBase64String(string $value): bool
    {
        return (bool)preg_match(self::BASE64_PATTERN, $value);
    }

    /**
     * Extract metadata from a data URI.
     *
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
