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
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\AgentFailed;
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
use Sentry\Laravel\Features\Ai\AiConversationTracker;
use Sentry\Laravel\Features\Ai\AiDataSanitizer;
use Sentry\Laravel\Features\Ai\AiInvocationData;
use Sentry\Laravel\Features\Ai\AiInvocationMeta;
use Sentry\Laravel\Features\Ai\AiMessage;
use Sentry\Laravel\Features\Ai\AiMessagePart;
use Sentry\Laravel\Features\Ai\AiProviderUrlResolver;
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

    /** Maximum tracked invocations before evicting oldest (prevents memory leaks in long-running processes). */
    private const MAX_TRACKED_INVOCATIONS = 100;

    /** @var BoundedOrderedMap<AiInvocationData> Per-agent-invocation state keyed by invocation ID. */
    private $invocations;

    /** @var BoundedOrderedMap<array{span: Span, parentSpan: Span|null}> Per-tool-invocation state keyed by tool invocation ID. */
    private $toolInvocations;

    /** @var BoundedOrderedMap<array{span: Span, parentSpan: Span|null}> Per-embeddings-invocation state keyed by invocation ID. */
    private $embeddingsInvocations;

    /** @var AiConversationTracker The conversation that gen_ai spans belong to. */
    private $conversations;

    public function __construct(Container $container)
    {
        parent::__construct($container);

        $this->conversations = new AiConversationTracker();

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
            && class_exists(\Laravel\Ai\Events\PromptingAgent::class);
    }

    public function onBoot(Dispatcher $events): void
    {
        $events->listen(\Laravel\Ai\Events\PromptingAgent::class, [$this, 'handlePromptingAgentForTracing']);
        $events->listen(\Laravel\Ai\Events\AgentPrompted::class, [$this, 'handleAgentPromptedForTracing']);
        $events->listen(\Laravel\Ai\Events\AgentFailed::class, [$this, 'handleAgentFailedForTracing']);
        $events->listen(\Laravel\Ai\Events\StreamingAgent::class, [$this, 'handlePromptingAgentForTracing']);
        $events->listen(\Laravel\Ai\Events\AgentStreamed::class, [$this, 'handleAgentPromptedForTracing']);
        $events->listen(\Laravel\Ai\Events\InvokingTool::class, [$this, 'handleInvokingToolForTracing']);
        $events->listen(\Laravel\Ai\Events\ToolInvoked::class, [$this, 'handleToolInvokedForTracing']);
        $events->listen(\Laravel\Ai\Events\GeneratingEmbeddings::class, [$this, 'handleGeneratingEmbeddingsForTracing']);
        $events->listen(\Laravel\Ai\Events\EmbeddingsGenerated::class, [$this, 'handleEmbeddingsGeneratedForTracing']);

        if (class_exists(RequestSending::class)) {
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
        $isStreaming = is_a($event, \Laravel\Ai\Events\StreamingAgent::class);

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
            $data->set('gen_ai.system_instructions', AiDataSanitizer::truncateString($instructions));
        }

        $agentSpan = $parentSpan->startChild(
            SpanContext::make()
                ->setOp('gen_ai.invoke_agent')
                ->setData($data->toArray())
                ->setOrigin('auto.ai.laravel')
                ->setDescription('invoke_agent ' . $model)
        );

        $this->startConversation($event->prompt->agent, $agentSpan);
        $this->conversations->attach($agentSpan);

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
                AiProviderUrlResolver::baseUrl($provider),
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
        $this->setConversationId($invocation, $conversationId);

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

    public function handleAgentFailedForTracing(AgentFailed $event): void
    {
        $invocation = $this->invocations->pull($event->invocationId);
        if ($invocation === null) {
            return;
        }

        // A failed turn of a new conversation that laravel/ai remembered has its conversation ID on the agent by now
        $this->setConversationId($invocation, $this->resolveConversationId($event->prompt->agent));

        try {
            $invocation->finishActiveChatSpan(SpanStatus::internalError());
            $invocation->span->setStatus(SpanStatus::internalError());
            $invocation->span->finish();
        } finally {
            SentrySdk::getCurrentHub()->setSpan($invocation->parentSpan);
        }
    }

    public function handleHttpRequestSending(RequestSending $event): void
    {
        if (!$this->isTracingFeatureEnabled(self::FEATURE_KEY_CHAT)) {
            return;
        }

        // Requests sent by a classification belong to its `gen_ai.evaluate` span, even if the agent's provider shares the host
        $currentSpan = SentrySdk::getCurrentHub()->getSpan();
        if ($currentSpan !== null && $currentSpan->getOp() === 'gen_ai.evaluate') {
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
        $this->conversations->attach($chatSpan);

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
            $data->set('gen_ai.tool.call.arguments', AiDataSanitizer::truncateString(AiDataSanitizer::encodeIfNotString($event->arguments)));
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

        $this->conversations->attach($span);

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
            $data->set('gen_ai.tool.call.result', AiDataSanitizer::truncateString(AiDataSanitizer::encodeIfNotString($event->result)));
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

        $this->conversations->attach($span);

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

        // laravel/ai 1.0 replaced the `tokens` count with a `usage` object
        $usage = $event->response->usage ?? null;
        $data->setNonZero('gen_ai.usage.input_tokens', $usage !== null ? $usage->inputTokens : ($event->response->tokens ?? null));

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

    /**
     * Method used so that other integrations that produce gen_ai spans can submit their spans
     * here which will be populated with the conversation ID once it becomes available.
     */
    public function attachSpanToConversation(Span $span): void
    {
        $this->conversations->attach($span);
    }

    /**
     * Set the conversation of the gen_ai spans started from now on in the current trace, replacing the current one.
     * Passing null forgets the current conversation.
     */
    public function setCurrentConversationId(?string $conversationId): void
    {
        if ($conversationId === null) {
            $this->conversations->end();

            return;
        }

        $span = SentrySdk::getCurrentHub()->getSpan();

        // Without a span there is no trace whose gen_ai spans could get the conversation ID
        if ($span !== null) {
            $this->conversations->start($span, $conversationId);
        }
    }

    /**
     * Start the conversation of an agent that remembers it, replacing the current one. A new conversation gets its ID once the first turn ends.
     */
    private function startConversation(Agent $agent, Span $agentSpan): void
    {
        if (!method_exists($agent, 'hasConversationParticipant')) {
            return;
        }

        $conversationId = $this->resolveConversationId($agent);

        if ($conversationId !== null || $agent->hasConversationParticipant()) {
            $this->conversations->start($agentSpan, $conversationId);
        }
    }

    private function setConversationId(AiInvocationData $invocation, ?string $conversationId): void
    {
        $invocation->setConversationIdOnSpans($conversationId);

        if ($conversationId !== null) {
            $this->conversations->resolve($invocation->span, $conversationId);
        }
    }

    private function resolveConversationId(Agent $agent): ?string
    {
        return method_exists($agent, 'currentConversation') ? $agent->currentConversation() : null;
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

        if (is_a($attachment, \Laravel\Ai\Files\RemoteImage::class)) {
            $modality = 'image';
            $type = 'uri';
            $content = $attachment->url ?? null;
        } elseif (is_a($attachment, \Laravel\Ai\Files\RemoteDocument::class)) {
            $modality = 'document';
            $type = 'uri';
            $content = $attachment->url ?? null;
        } elseif (is_a($attachment, \Laravel\Ai\Files\RemoteAudio::class)) {
            $modality = 'audio';
            $type = 'uri';
            $content = $attachment->url ?? null;
        } elseif (is_a($attachment, \Laravel\Ai\Files\ProviderImage::class)) {
            $modality = 'image';
            $type = 'file_id';
            $content = $attachment->id ?? null;
        } elseif (is_a($attachment, \Laravel\Ai\Files\ProviderDocument::class)) {
            $modality = 'document';
            $type = 'file_id';
            $content = $attachment->id ?? null;
        } else {
            $class = \get_class($attachment);
            if (is_a($attachment, \Laravel\Ai\Files\Image::class, true)
                || strpos($class, 'Image') !== false) {
                $modality = 'image';
            } elseif (is_a($attachment, \Laravel\Ai\Files\Document::class, true)
                || strpos($class, 'Document') !== false) {
                $modality = 'document';
            } elseif (is_a($attachment, \Laravel\Ai\Files\Audio::class, true)
                || strpos($class, 'Audio') !== false) {
                $modality = 'audio';
            } else {
                $modality = 'file';
            }
            $type = 'blob';
            $content = AiDataSanitizer::BLOB_SUBSTITUTE;
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
                    ->setArguments(AiDataSanitizer::encodeIfNotString($toolCall->arguments));
            }
        }
        
        if (!empty($parts)) {
            $messages[] = new AiMessage('assistant', $parts);
        }
        
        foreach ($source->toolResults as $toolResult) {
            if (!is_a($toolResult, ToolResult::class)) {
                continue;
            }
            $resultContent = AiDataSanitizer::encodeIfNotString($toolResult->result);
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
        
        return AiDataSanitizer::encodeIfNotString($definitions);
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
                    $part->setContent(AiDataSanitizer::BLOB_SUBSTITUTE);
                } elseif ($part->getContent() !== null) {
                    $part->setContent(AiDataSanitizer::redactBinaryInString($part->getContent()));
                }
                
                if ($part->getContent() !== null) {
                    $part->setContent(AiDataSanitizer::truncateContentString($part->getContent()));
                }
                if ($part->getArguments() !== null) {
                    $part->setArguments(AiDataSanitizer::truncateContentString($part->getArguments()));
                }
            }
        }

        // encode all messages and see if they fit into our bytes budget
        $encoded = json_encode($messages);
        if ($encoded !== false && \strlen($encoded) <= AiDataSanitizer::MAX_MESSAGE_BYTES) {
            return $encoded;
        }

        // if they are too big then we just serialize the last message and truncate if necessary
        $lastMessage = end($messages);
        $encoded = json_encode([$lastMessage]);
        return $encoded !== false ? AiDataSanitizer::truncateString($encoded) : '[]';
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
            if ($totalBytes + $entryBytes > AiDataSanitizer::MAX_MESSAGE_BYTES) {
                break;
            }

            $kept[] = $inputs[$i];
            $totalBytes += $entryBytes;
        }

        if (empty($kept)) {
            $firstInput = reset($inputs);
            if (\is_string($firstInput)) {
                $firstInput = AiDataSanitizer::truncateContentString($firstInput);
            }

            $kept = [$firstInput];
        }

        $encoded = json_encode($kept);

        return AiDataSanitizer::truncateString($encoded !== false ? $encoded : '[]');
    }
}
