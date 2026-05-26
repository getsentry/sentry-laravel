<?php

namespace Sentry\Laravel\Features;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\Support\Collection;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\TextResponse;
use Sentry\Laravel\Features\Ai\AiInvocationData;
use Sentry\Laravel\Features\Ai\AiInvocationMeta;
use Sentry\Laravel\Features\Ai\AiMessage;
use Sentry\Laravel\Features\Ai\AiMessagePart;
use Sentry\Laravel\Features\Ai\AiSpanDataBag;
use Sentry\Laravel\Util\BoundedOrderedMap;
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

    /** @var BoundedOrderedMap<AiInvocationData> Per-agent-invocation state keyed by invocation ID. */
    private $invocations;

    /** @var BoundedOrderedMap<array{span: Span, parentSpan: Span|null}> Per-tool-invocation state keyed by tool invocation ID. */
    private $toolInvocations;

    /** @var BoundedOrderedMap<array{span: Span, parentSpan: Span|null}> Per-embeddings-invocation state keyed by invocation ID. */
    private $embeddingsInvocations;

    public function __construct(Container $container)
    {
        parent::__construct($container);

        $this->invocations = new BoundedOrderedMap(self::MAX_TRACKED_INVOCATIONS, function (AiInvocationData $invocation): void {
            if ($invocation->activeChatSpan !== null) {
                $invocation->activeChatSpan->setStatus(SpanStatus::deadlineExceeded());
                $invocation->activeChatSpan->finish();
            }

            $invocation->span->setStatus(SpanStatus::deadlineExceeded());
            $invocation->span->finish();
        });

        /** @param array{span: Span, parentSpan: Span|null} $invocation */
        $finishEvictedSpanInvocation = function (array $invocation): void {
            $invocation['span']->setStatus(SpanStatus::deadlineExceeded());
            $invocation['span']->finish();
        };

        $this->toolInvocations = new BoundedOrderedMap(self::MAX_TRACKED_INVOCATIONS, $finishEvictedSpanInvocation);
        $this->embeddingsInvocations = new BoundedOrderedMap(self::MAX_TRACKED_INVOCATIONS, $finishEvictedSpanInvocation);
    }

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

        $data = new AiSpanDataBag([
            'gen_ai.operation.name' => 'invoke_agent',
            'gen_ai.agent.name' => $agentName,
            'gen_ai.request.model' => $model
        ]);

        if ($isStreaming) {
            $data->set('gen_ai.response.streaming', true);
        }

        $provider = $event->prompt->provider;
        $providerName = is_a($provider, Provider::class) ? $provider->name() : null;
        $data->set('gen_ai.provider.name', $providerName);

        $temperature = $this->resolveAgentAttribute($event->prompt->agent, Temperature::class);
        $data->set('gen_ai.request.temperature', $temperature);

        $maxTokens = $this->resolveAgentAttribute($event->prompt->agent, MaxTokens::class);
        $data->set('gen_ai.request.max_tokens', $maxTokens);

        $toolDefinitions = $this->resolveToolDefinitions($event->prompt->agent);
        $data->set('gen_ai.tool.definitions', $toolDefinitions);

        $attachments = $this->resolveAttachments($event->prompt);

        if ($this->shouldSendDefaultPii()) {
            $inputMessages = $this->buildUserInputMessageFromParts(
                $event->prompt->prompt,
                $attachments
            );
            $data->set('gen_ai.input.messages', $this->truncateMessages($inputMessages));

            $instructions = (string) $event->prompt->agent->instructions();
            $data->set('gen_ai.system_instructions', $this->truncateString($instructions));
        }

        $agentSpan = $parentSpan->startChild(
            SpanContext::make()
                ->setOp('gen_ai.invoke_agent')
                ->setData($data->toArray())
                ->setOrigin('auto.ai.laravel')
                ->setDescription('invoke_agent ' . $model)
        );

        $this->invocations->set(
            $event->invocationId,
            new AiInvocationData(
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
                $this->resolveProviderUrlPrefix($provider),
                $isStreaming
            )
        );

        SentrySdk::getCurrentHub()->setSpan($agentSpan);
    }

    public function handleAgentPromptedForTracing(AgentPrompted $event): void
    {
        $invocationId = $event->invocationId;
        $invocation = $this->invocations->get($invocationId);
        if ($invocation === null) {
            return;
        }

        $invocation->finishActiveChatSpan();

        $agentSpan = $invocation->span;
        $parentSpan = $invocation->parentSpan;

        $conversationId = $event->response->conversationId;

        $this->enrichChatSpansWithStepData($invocation, $event->response);
        $invocation->setConversationIdOnSpans($conversationId);

        $data = new AiSpanDataBag($agentSpan->getData());
        $data->set('gen_ai.response.model', $event->response->meta->model);
        $data->setIfNotExists('gen_ai.provider.name', $event->response->meta->provider);
        $data->setTokenUsage($event->response->usage);
        
        if ($this->shouldSendDefaultPii()) {
            $outputMessages = $this->buildOutputMessages($event->response);
            $data->set('gen_ai.output.messages', $this->truncateMessages($outputMessages));
        }

        $agentSpan->setData($data->toArray());
        $agentSpan->setStatus(SpanStatus::ok());
        $agentSpan->finish();

        $this->invocations->pull($invocationId);

        if ($parentSpan !== null) {
            SentrySdk::getCurrentHub()->setSpan($parentSpan);
        }
    }

    public function handleHttpRequestSending(RequestSending $event): void
    {
        if (!$this->isTracingFeatureEnabled(self::FEATURE_KEY_CHAT)) {
            return;
        }

        $invocation = $this->findMatchingInvocation($event->request->url());
        if ($invocation === null) {
            return;
        }

        $invocation->finishActiveChatSpan();

        $meta = $invocation->meta;
        $model = $meta->model;

        $data = new AiSpanDataBag([
            'gen_ai.operation.name' => 'chat',
        ]);

        if ($invocation->isStreaming) {
            $data->set('gen_ai.response.streaming', true);
        }
        $data->set('gen_ai.request.model', $model);
        $data->set('gen_ai.agent.name', $meta->agentName);
        $data->set('gen_ai.provider.name', $meta->providerName);
        $data->set('gen_ai.tool.definitions', $meta->toolDefinitions);

        $chatSpan = $invocation->span->startChild(
            SpanContext::make()
                ->setOp('gen_ai.chat')
                ->setData($data->toArray())
                ->setOrigin('auto.ai.laravel')
                ->setDescription('chat ' . ($model ?? 'unknown'))
        );

        $invocation->activeChatSpan = $chatSpan;
        $invocation->chatSpans[] = $chatSpan;

        SentrySdk::getCurrentHub()->setSpan($chatSpan);
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

        $data = new AiSpanDataBag([
            'gen_ai.operation.name' => 'execute_tool',
            'gen_ai.tool.name' => $toolDef['name'],
            'gen_ai.tool.type' => $toolDef['type'],
            'gen_ai.agent.name' => $agentName,
        ]);
        $data->set('gen_ai.tool.description', $toolDef['description'] ?? null);

        if ($this->shouldSendDefaultPii() && !empty($event->arguments)) {
            $data->set('gen_ai.tool.call.arguments', $this->truncateString($this->encodeIfNotString($event->arguments)));
        }

        $span = $parentSpan->startChild(
            SpanContext::make()
                ->setOp('gen_ai.execute_tool')
                ->setData($data->toArray())
                ->setOrigin('auto.ai.laravel')
                ->setDescription('execute_tool ' . $toolDef['name'])
        );

        $this->toolInvocations->set($event->toolInvocationId, [
            'span' => $span,
            'parentSpan' => $parentSpan,
        ]);

        $invocation = $this->invocations->get($event->invocationId);
        if ($invocation !== null) {
            $invocation->toolSpans[] = $span;
        }

        SentrySdk::getCurrentHub()->setSpan($span);
    }

    public function handleToolInvokedForTracing(ToolInvoked $event): void
    {
        $invocation = $this->toolInvocations->pull($event->toolInvocationId);
        if ($invocation === null) {
            return;
        }

        $span = $invocation['span'];
        $data = new AiSpanDataBag($span->getData());

        if ($this->shouldSendDefaultPii()) {
            $data->set('gen_ai.tool.call.result', $this->truncateString($this->encodeIfNotString($event->result)));
        }

        $span->setData($data->toArray());
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

        $data = new AiSpanDataBag([
            'gen_ai.operation.name' => 'embeddings',
            'gen_ai.request.model' => $event->model,
            'gen_ai.provider.name' => $event->provider->name(),
        ]);

        if ($this->shouldSendDefaultPii()) {
            $data->set('gen_ai.embeddings.input', $this->truncateEmbeddingInputs($event->prompt->inputs));
        }

        $span = $parentSpan->startChild(
            SpanContext::make()
                ->setOp('gen_ai.embeddings')
                ->setData($data->toArray())
                ->setOrigin('auto.ai.laravel')
                ->setDescription('embeddings ' . $event->model)
        );

        $this->embeddingsInvocations->set($event->invocationId, [
            'span' => $span,
            'parentSpan' => $parentSpan,
        ]);

        SentrySdk::getCurrentHub()->setSpan($span);
    }

    public function handleEmbeddingsGeneratedForTracing(EmbeddingsGenerated $event): void
    {
        $invocationId = $event->invocationId;
        $invocation = $this->embeddingsInvocations->pull($invocationId);

        if ($invocation === null) {
            return;
        }

        $span = $invocation['span'];
        $data = new AiSpanDataBag($span->getData());
        $data->set('gen_ai.response.model', $event->response->meta->model);
        $data->setIfNotExists('gen_ai.provider.name', $event->response->meta->provider);
        $data->setNonZero('gen_ai.usage.input_tokens', $event->response->tokens ?? 0);

        $span->setData($data->toArray());
        $span->setStatus(SpanStatus::ok());
        $span->finish();

        if ($invocation['parentSpan'] !== null) {
            SentrySdk::getCurrentHub()->setSpan($invocation['parentSpan']);
        }
    }

    public function handleHttpResponseReceived(ResponseReceived $event): void
    {
        $invocation = $this->findMatchingInvocation($event->request->url());
        if ($invocation !== null) {
            $status = SpanStatus::createFromHttpStatusCode($event->response->status());
            $invocation->finishActiveChatSpan($status);
        }
    }

    public function handleHttpConnectionFailed(ConnectionFailed $event): void
    {
        $invocation = $this->findMatchingInvocation($event->request->url());
        if ($invocation !== null) {
            $invocation->finishActiveChatSpan(SpanStatus::internalError());
        }
    }

    private function findMatchingInvocation(string $url): ?AiInvocationData
    {
        foreach ($this->invocations->newestFirst() as $invocationId => $invocation) {
            if ($invocation->urlPrefix !== null && substr($url, 0, \strlen($invocation->urlPrefix)) === $invocation->urlPrefix) {
                return $invocation;
            }
        }

        return null;
    }

    /**
     * Enrich chat spans with step data. With steps (non-streaming), each step maps 1:1 to a chat span.
     * Without steps (streaming), response-level data is used instead.
     */
    private function enrichChatSpansWithStepData(AiInvocationData $invocation, AgentResponse $response): void
    {
        $chatSpans = $invocation->chatSpans;
        if (empty($chatSpans)) {
            return;
        }

        /**
         * @var $steps Step[]
         */
        $steps = $response->steps;

        foreach ($chatSpans as $index => $chatSpan) {
            $data = new AiSpanDataBag($chatSpan->getData());
            $step = $steps[$index] ?? null;

            if ($step !== null) {
                $model = $step->meta->model;
                $usage = $step->usage;
                $data->set('gen_ai.response.finish_reasons', $step->finishReason->value);
            } else {
                $model = $response->meta->model;
                $usage = \count($chatSpans) === 1 ? $response->usage : null;
            }
            
            $data->set('gen_ai.response.model', $model);
            $data->setTokenUsage($usage);

            if ($this->shouldSendDefaultPii()) {
                if ($index === 0) {
                    $meta = $invocation->meta;
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
                
                $data->set('gen_ai.input.messages', $this->truncateMessages($inputMessages));

                $outputSource = $step ?? $response;

                $outputMessages = $this->buildOutputMessages($outputSource);
                $data->set('gen_ai.output.messages', $this->truncateMessages($outputMessages));
            }

            $chatSpan->setData($data->toArray());
        }
    }

    /**
     * @param Provider|TextProvider $provider
     */
    private function resolveProviderUrlPrefix($provider): ?string
    {
        if (!$provider instanceof Provider) {
            return null;
        }
        // Try to get the URL from laravel AI config and then
        // from the prism config. Just using prism config here might not be enough if someone
        // configures values in config/ai.php
        $url = config("ai.providers.{$provider->name()}.url")
            ?? config("prism.providers.{$provider->driver()}.url");

        return \is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * @return AiMessagePart[]
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

    private function transformAttachment(object $attachment): AiMessagePart
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
        
        return (new AiMessagePart($type))
            ->setModality($modality)
            ->setContent($content)
            ->setName($name)
            ->setMimeType($mimeType);
    }

    /**
     * @param AiMessagePart[] $attachmentParts
     * @return AiMessage[]
     */
    private function buildUserInputMessageFromParts(string $promptText, array $attachmentParts): array
    {
        $parts = $promptText !== ''
            ? [(new AiMessagePart('text'))->setContent($promptText)]
            : [];

        $parts = array_merge($parts, $attachmentParts);

        if (empty($parts)) {
            return [];
        }

        return [new AiMessage('user', $parts)];
    }

    /**
     * @param array<int, Step>|Collection<int, Step> $steps
     * @return AiMessage[]
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
     * @return AiMessage[]
     */
    private function buildOutputMessages($source): array
    {
        $messages = [];
        $parts = [];
        if ($source->text !== '') {
            $parts[] = (new AiMessagePart('text'))->setContent($source->text);
        }
        
        foreach ($source->toolCalls as $toolCall) {
            if (is_a($toolCall, ToolCall::class)) {
                $parts[] = (new AiMessagePart('tool_call'))
                    ->setId($toolCall->id)
                    ->setName($toolCall->name)
                    ->setArguments($this->encodeIfNotString($toolCall->arguments));
            }
        }
        
        if (!empty($parts)) {
            $messages[] = new AiMessage('assistant', $parts);
        }
        
        foreach ($source->toolResults as $toolResult) {
            if (!is_a($toolResult, ToolResult::class)) {
                continue;
            }
            $resultContent = $this->encodeIfNotString($toolResult->result);
            if ($resultContent === null) {
                continue;
            }
            
            $messages[] = new AiMessage('tool', [
                (new AiMessagePart('tool_call_response'))
                    ->setContent($resultContent)
                    ->setId($toolResult->id)
                    ->setName($toolResult->name)
            ]);
        }
        
        return $messages;
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
        
        return $this->encodeIfNotString($definitions);
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

        try {
            $factory = new JsonSchemaTypeFactory();
            $properties = $tool->schema($factory);
            if (!empty($properties)) {
                $objectType = new ObjectType($properties);
                $definition['parameters'] = $objectType->toArray();
            }
        } catch (\Throwable $e) {
            // Ignore schema resolution failures.
        }

        return $definition;
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

    private function truncateString(?string $value, int $maxBytes = self::MAX_MESSAGE_BYTES): ?string
    {
        if ($value === null) {
            return null;
        }
        
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
     * @param AiMessage[] $messages
     */
    private function truncateMessages(array $messages): string
    {
        if (empty($messages)) {
            return '[]';
        }

        foreach ($messages as $message) {
            foreach ($message->getParts() as $part) {
                if ($part->getType() === 'blob') {
                    $part->setContent(self::BLOB_SUBSTITUTE);
                } elseif ($part->getContent() !== null) {
                    $part->setContent($this->redactBinaryInString($part->getContent()));
                }
                
                if ($part->getContent() !== null) {
                    $part->setContent($this->truncateContentString($part->getContent()));
                }
                if ($part->getArguments() !== null) {
                    $part->setArguments($this->truncateContentString($part->getArguments()));
                }
            }
        }

        // encode all messages and see if they fit into our bytes budget
        $encoded = json_encode($messages);
        if ($encoded !== false && \strlen($encoded) <= self::MAX_MESSAGE_BYTES) {
            return $encoded;
        }

        // if they are too big then we just serialize the last message and truncate if necessary
        $lastMessage = end($messages);
        $encoded = json_encode([$lastMessage]);
        return $encoded !== false ? $this->truncateString($encoded) : '[]';
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

    private function redactBinaryInString(string $value): string
    {
        if ($this->isBinaryString($value)) {
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
     * Encodes arbitrary values using `json_encode` unless they are strings already, in which
     * case the same string is returned.
     * If `json_encode` fails, it will return null. The reason for that is that we don't distinguish
     * a lot here between null and false, both mean that we do not want to include them as facts.
     *
     * @var mixed|null $data
     */
    private function encodeIfNotString($data = null): ?string
    {
        if ($data === null) {
            return null;
        }
        if (is_string($data)) {
            return $data;
        }
        $encoded = \json_encode($data);
        return $encoded !== false ? $encoded : null;
    }
}
