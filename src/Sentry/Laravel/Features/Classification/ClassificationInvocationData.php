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

    /**
     * @var string
     */
    public $providerName;

    /**
     * @var string
     */
    public $model;

    /**
     * The host the provider sends requests to, or null when it is unknown.
     *
     * @var string|null
     */
    public $providerHost;

    public function __construct(Span $span, Span $parentSpan, string $providerName, string $model, ?string $providerHost)
    {
        $this->span = $span;
        $this->parentSpan = $parentSpan;
        $this->providerName = $providerName;
        $this->model = $model;
        $this->providerHost = $providerHost;
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
