<?php

namespace Sentry\Laravel\Features\Classification;

use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;

/**
 * @internal
 */
class ClassificationInvocationData
{
    /**
     * @var Span
     */
    public $span;

    /**
     * @var Span
     */
    public $parentSpan;

    public function __construct(Span $span, Span $parentSpan)
    {
        $this->span = $span;
        $this->parentSpan = $parentSpan;
    }

    public function finishSpan(SpanStatus $status): void
    {
        $this->span->setStatus($status);
        $this->span->finish();
    }

    public function restoreParentSpan(): void
    {
        $hub = SentrySdk::getCurrentHub();

        if ($hub->getSpan() === $this->span) {
            $hub->setSpan($this->parentSpan);
        }
    }
}
