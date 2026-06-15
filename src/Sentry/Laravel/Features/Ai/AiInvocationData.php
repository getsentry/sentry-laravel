<?php

namespace Sentry\Laravel\Features\Ai;

use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;

/**
 * @internal
 */
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

    public function __construct(Span $span, ?Span $parentSpan, AiInvocationMeta $meta, ?string $urlPrefix, bool $isStreaming)
    {
        $this->span = $span;
        $this->parentSpan = $parentSpan;
        $this->meta = $meta;
        $this->urlPrefix = $urlPrefix;
        $this->isStreaming = $isStreaming;
    }
    
    public function setConversationIdOnSpans(?string $conversationId): void
    {
        if ($conversationId === null) {
            return;
        }

        $spans = array_merge([$this->span], $this->toolSpans, $this->chatSpans);
        foreach ($spans as $span) {
            $data = $span->getData();
            $data['gen_ai.conversation.id'] = $conversationId;
            $span->setData($data);
        }
    }

    public function finishActiveChatSpan(?SpanStatus $status = null): void
    {
        $chatSpan = $this->activeChatSpan;
        if ($chatSpan === null) {
            return;
        }

        $this->activeChatSpan = null;

        $chatSpan->setStatus($status ?? SpanStatus::ok());
        $chatSpan->finish();

        SentrySdk::getCurrentHub()->setSpan($this->span);
    }
}
