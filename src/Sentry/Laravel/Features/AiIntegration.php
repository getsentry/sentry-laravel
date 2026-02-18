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

    /** Maximum total byte size for serialized message data (matches Python SDK). */
    private const MAX_MESSAGE_BYTES = 20000;

    /** Maximum character length for a single message's content string (matches Python SDK). */
    private const MAX_SINGLE_MESSAGE_CONTENT_CHARS = 10000;

    /** Placeholder for binary content that should not be sent to Sentry. */
    private const BLOB_SUBSTITUTE = '[Blob substitute]';

    /** Regex pattern to detect data URIs (e.g. data:image/png;base64,...). */
    private const DATA_URI_PATTERN = '/^data:([^;,]+)?(?:;([^,]*))?,(.*)/s';

    /** Regex pattern to detect standalone base64-encoded strings (100+ chars). */
    private const BASE64_PATTERN = '/^[A-Za-z0-9+\/]{100,}={0,2}$/';

    /** Maximum tracked invocations before evicting oldest (prevents memory leaks in long-running processes). */
    private const MAX_TRACKED_INVOCATIONS = 100;

    /** @var array<string, array<string, mixed>> Per-agent-invocation state keyed by invocation ID. */
    private $invocations = [];

    /** @var array<string, array{span: Span, parentSpan: Span|null}> Per-tool-invocation state keyed by tool invocation ID. */
    private $toolInvocations = [];

    /** @var array<string, array{span: Span, parentSpan: Span|null}> Per-embeddings-invocation state keyed by invocation ID. */
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
        if (!$this->isTracingFeatureEnabled('gen_ai_invoke_agent')) {
            return;
        }

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

        $this->evictOldestIfNeeded($this->invocations);

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

    public function handleAgentPromptedForTracing(object $event): void
    {
        $invocationId = $event->invocationId;

        if (!isset($this->invocations[$invocationId])) {
            return;
        }

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

    public function handleInvokingToolForTracing(object $event): void
    {
        if (!$this->isTracingFeatureEnabled('gen_ai_execute_tool')) {
            return;
        }

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
                ->setDescription("execute_tool {$toolName}")
        );

        $this->evictOldestIfNeeded($this->toolInvocations);

        $this->toolInvocations[$event->toolInvocationId] = [
            'span' => $span,
            'parentSpan' => $parentSpan,
        ];

        if (isset($this->invocations[$event->invocationId])) {
            $this->invocations[$event->invocationId]['toolSpans'][] = $span;
        }

        SentrySdk::getCurrentHub()->setSpan($span);
    }

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
            $resultString = \is_string($event->result) ? $event->result : json_encode($event->result);
            if ($resultString !== false) {
                $data['gen_ai.tool.call.result'] = $this->truncateString($resultString);
            }
        }

        $span->setData($data);
        $span->setStatus(SpanStatus::ok());
        $span->finish();

        if ($inv['parentSpan'] !== null) {
            SentrySdk::getCurrentHub()->setSpan($inv['parentSpan']);
        }
    }

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
            if (\is_array($inputs) && !empty($inputs)) {
                $data['gen_ai.embeddings.input'] = $this->truncateEmbeddingInputs($inputs);
            }
        }

        $span = $parentSpan->startChild(
            SpanContext::make()
                ->setOp('gen_ai.embeddings')
                ->setData($data)
                ->setOrigin('auto.ai.laravel')
                ->setDescription("embeddings " . ($model ?? 'unknown'))
        );

        $this->evictOldestIfNeeded($this->embeddingsInvocations);

        $this->embeddingsInvocations[$event->invocationId] = [
            'span' => $span,
            'parentSpan' => $parentSpan,
        ];

        SentrySdk::getCurrentHub()->setSpan($span);
    }

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

    public function handleHttpRequestSending(RequestSending $event): void
    {
        if (!$this->isTracingFeatureEnabled('gen_ai_chat')) {
            return;
        }

        $invocationId = $this->findMatchingInvocation($event->request->url());

        if ($invocationId === null || !isset($this->invocations[$invocationId])) {
            return;
        }

        $inv = &$this->invocations[$invocationId];
        $meta = $inv['meta'];
        $model = $meta['model'] ?? null;

        $data = [
            'gen_ai.operation.name' => 'chat',
        ];

        if ($inv['isStreaming'] ?? false) {
            $data['gen_ai.response.streaming'] = true;
        }

        if ($model !== null) {
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
                ->setDescription("chat " . ($model ?? 'unknown'))
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

    private function findMatchingInvocation(string $url): ?string
    {
        foreach (array_reverse($this->invocations, true) as $invocationId => $inv) {
            if ($inv['urlPrefix'] !== null && substr($url, 0, \strlen($inv['urlPrefix'])) === $inv['urlPrefix']) {
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
     * Enrich chat spans with step data. With steps (non-streaming), each step maps 1:1 to a chat span.
     * Without steps (streaming), response-level data is used instead.
     */
    private function enrichChatSpansWithStepData(string $invocationId, object $response, ?string $conversationId = null): void
    {
        $chatSpans = $this->invocations[$invocationId]['chatSpans'] ?? [];

        if (empty($chatSpans)) {
            return;
        }

        $steps = $response->steps ?? null;

        if (\is_object($steps) && method_exists($steps, 'all')) {
            $steps = $steps->all();
        } elseif (\is_object($steps) && method_exists($steps, 'toArray')) {
            $steps = $steps->toArray();
        }

        $stepsArray = \is_array($steps) ? array_values($steps) : [];
        $hasSteps = !empty($stepsArray);
        $lastIndex = \count($chatSpans) - 1;

        foreach ($chatSpans as $index => $chatSpan) {
            $data = $chatSpan->getData();

            if ($conversationId !== null) {
                $data['gen_ai.conversation.id'] = $conversationId;
            }

            $step = $stepsArray[$index] ?? null;

            $model = $step !== null
                ? $this->flexGet($this->flexGet($step, 'meta'), 'model')
                : $this->flexGet($this->flexGet($response, 'meta'), 'model');

            if ($model !== null) {
                $data['gen_ai.request.model'] = $model;
                $data['gen_ai.response.model'] = $model;
                $chatSpan->setDescription("chat {$model}");
            }

            $usage = $step !== null
                ? $this->flexGet($step, 'usage')
                : (\count($chatSpans) === 1 ? $this->flexGet($response, 'usage') : null);

            if ($usage !== null) {
                $this->setTokenUsage($data, $usage);
            }

            if ($step !== null) {
                $finishReason = $this->flexGet($step, 'finishReason');
                if ($finishReason !== null) {
                    $data['gen_ai.response.finish_reasons'] = \is_object($finishReason) && property_exists($finishReason, 'value')
                        ? $finishReason->value
                        : (string)$finishReason;
                }
            }

            if ($this->shouldSendDefaultPii()) {
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

        return \is_string($url) && $url !== '' ? $url : null;
    }

    /**
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

        if (\is_object($attachments) && method_exists($attachments, 'all')) {
            $attachments = $attachments->all();
        } elseif (\is_object($attachments) && method_exists($attachments, 'toArray')) {
            $attachments = $attachments->toArray();
        }

        if (!\is_array($attachments) || empty($attachments)) {
            return [];
        }

        $parts = [];
        foreach ($attachments as $attachment) {
            if (!\is_object($attachment)) {
                continue;
            }

            try {
                $part = $this->transformAttachment($attachment);
                if ($part !== null) {
                    $parts[] = $part;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $parts;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function transformAttachment(object $attachment): ?array
    {
        $modality = $this->resolveAttachmentModality($attachment);

        $arrayForm = method_exists($attachment, 'toArray') ? $attachment->toArray() : null;

        $type = $arrayForm['type'] ?? null;
        $name = method_exists($attachment, 'name') ? $attachment->name() : ($attachment->name ?? null);
        $mimeType = method_exists($attachment, 'mimeType')
            ? $this->safeCall(function () use ($attachment) {
                return $attachment->mimeType();
            })
            : null;

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

    private function resolveAttachmentModality(object $attachment): string
    {
        $class = \get_class($attachment);

        if (is_a($attachment, 'Laravel\Ai\Files\Image', true)
            || strpos($class, 'Image') !== false) {
            return 'image';
        }

        if (is_a($attachment, 'Laravel\Ai\Files\Document', true)
            || strpos($class, 'Document') !== false) {
            return 'document';
        }

        if (is_a($attachment, 'Laravel\Ai\Files\Audio', true)
            || strpos($class, 'Audio') !== false) {
            return 'audio';
        }

        return 'file';
    }

    /**
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
     * @return mixed
     */
    private function safeCall(callable $fn)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
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
     * @param object|array $source
     * @return array<int, array<string, mixed>>
     */
    private function buildOutputMessages($source): array
    {
        $messages = [];
        $parts = [];

        $text = $this->flexGet($source, 'text');
        if ($text !== null && $text !== '') {
            $parts[] = ['type' => 'text', 'content' => $text];
        }

        $toolCalls = $this->flexGet($source, 'toolCalls');
        if ($toolCalls !== null) {
            if (\is_object($toolCalls) && method_exists($toolCalls, 'all')) {
                $toolCalls = $toolCalls->all();
            } elseif (\is_object($toolCalls) && method_exists($toolCalls, 'toArray')) {
                $toolCalls = $toolCalls->toArray();
            }

            if (\is_array($toolCalls)) {
                foreach ($toolCalls as $toolCall) {
                    $parts[] = $this->buildToolCallPart($toolCall);
                }
            }
        }

        if (!empty($parts)) {
            $messages[] = ['role' => 'assistant', 'parts' => $parts];
        }

        $toolResults = $this->flexGet($source, 'toolResults');
        if ($toolResults !== null) {
            if (\is_object($toolResults) && method_exists($toolResults, 'all')) {
                $toolResults = $toolResults->all();
            } elseif (\is_object($toolResults) && method_exists($toolResults, 'toArray')) {
                $toolResults = $toolResults->toArray();
            }
        }
        if (\is_array($toolResults)) {
            foreach ($toolResults as $toolResult) {
                $result = $this->flexGet($toolResult, 'result');
                if ($result === null) {
                    continue;
                }

                $resultContent = \is_string($result) ? $result : json_encode($result);
                if ($resultContent === false) {
                    continue;
                }
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

    /**
     * @param object|array $toolCall
     */
    private function buildToolCallPart($toolCall): array
    {
        $part = ['type' => 'tool_call'];

        $name = $this->flexGet($toolCall, 'name');
        if ($name !== null) {
            $part['name'] = $name;
        }

        $args = $this->flexGet($toolCall, 'arguments');
        if ($args !== null) {
            $encoded = \is_string($args) ? $args : json_encode($args);
            if ($encoded !== false) {
                $part['arguments'] = $encoded;
            }
        }

        return $part;
    }

    /**
     * @return int|float|string|null
     */
    private function resolveAgentAttribute(object $agent, string $attributeClass)
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

        if (empty($definitions)) {
            return null;
        }

        $encoded = json_encode($definitions);

        return $encoded !== false ? $encoded : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveToolParameters(object $tool): ?array
    {
        if (!method_exists($tool, 'schema') || !class_exists('Illuminate\JsonSchema\JsonSchemaTypeFactory')) {
            return null;
        }

        try {
            $factory = new \Illuminate\JsonSchema\JsonSchemaTypeFactory();
            $properties = $tool->schema($factory);

            if (empty($properties) || !\is_array($properties)) {
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
            if (\is_string($name) && $name !== '') {
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

        if (\is_string($description) && $description !== '') {
            return $description;
        }

        if (\is_object($description) && method_exists($description, '__toString')) {
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

        if ($instructions === null) {
            return null;
        }

        return \is_string($instructions) ? $instructions : (string)$instructions;
    }

    /**
     * @param array<string, mixed> $data
     * @param object|array $usage
     */
    private function setTokenUsage(array &$data, $usage): void
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
     * @param object|array|null $source
     * @return mixed
     */
    private function flexGet($source, string $key)
    {
        if ($source === null) {
            return null;
        }

        if (\is_object($source)) {
            return property_exists($source, $key) ? $source->{$key} : null;
        }

        return $source[$key] ?? null;
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
            if (isset($oldest['span']) && $oldest['span'] instanceof Span) {
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

        for ($i = \count($inputs) - 1; $i >= 0; $i--) {
            $inputJson = json_encode($inputs[$i]);
            $entryBytes = \strlen($inputJson) + (empty($kept) ? 0 : 1); // +1 for comma separator

            if ($totalBytes + $entryBytes > self::MAX_MESSAGE_BYTES) {
                break;
            }

            array_unshift($kept, $inputs[$i]);
            $totalBytes += $entryBytes;
        }

        if (empty($kept)) {
            $lastInput = end($inputs);
            if (\is_string($lastInput)) {
                $lastInput = $this->truncateContentString($lastInput);
            }
            $kept = [$lastInput];
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
        return (bool)preg_match(self::DATA_URI_PATTERN, $value);
    }

    private function isBase64String(string $value): bool
    {
        return (bool)preg_match(self::BASE64_PATTERN, $value);
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
