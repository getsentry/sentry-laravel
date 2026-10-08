<?php

namespace Sentry\Laravel\Features\Ai;

use Sentry\Tracing\Span;

/**
 * Tracks the conversation ID so that it can be applied to gen_ai spans that do not expose them, such as classification
 *
 * @internal
 */
class AiConversationTracker
{
    /**
     * @var string|null
     */
    private $traceId = null;

    /**
     * The conversation ID can be null if no agent call has finished yet.
     *
     * @var string|null
     */
    private $conversationId = null;

    /**
     * The spans started while the new conversation has no ID yet.
     *
     * @var list<Span>
     */
    private $pendingSpans = [];

    /**
     * Start a conversation in the trace of the given span, replacing the current one.
     *
     * A new conversation is started without an ID, since laravel/ai only creates it once the first turn ends.
     */
    public function start(Span $span, ?string $conversationId): void
    {
        $this->traceId = (string) $span->getTraceId();
        $this->conversationId = $conversationId;
        $this->pendingSpans = [];
    }

    /**
     * Set the ID of the new conversation on the spans started without it, or switch to another conversation.
     */
    public function resolve(Span $span, string $conversationId): void
    {
        if (!$this->isCurrentTrace($span) || $this->conversationId !== null) {
            $this->start($span, $conversationId);

            return;
        }

        $this->conversationId = $conversationId;

        foreach ($this->pendingSpans as $pendingSpan) {
            self::setConversationId($pendingSpan, $conversationId);
        }

        $this->pendingSpans = [];
    }

    public function attach(Span $span): void
    {
        if (!$this->isCurrentTrace($span)) {
            // Forget a conversation of an earlier trace
            $this->traceId = null;
            $this->conversationId = null;
            $this->pendingSpans = [];

            return;
        }

        if ($this->conversationId === null) {
            $this->pendingSpans[] = $span;

            return;
        }

        self::setConversationId($span, $this->conversationId);
    }

    private function isCurrentTrace(Span $span): bool
    {
        return $this->traceId !== null && $this->traceId === (string) $span->getTraceId();
    }

    private static function setConversationId(Span $span, string $conversationId): void
    {
        $data = $span->getData();
        $data['gen_ai.conversation.id'] = $conversationId;
        $span->setData($data);
    }
}
