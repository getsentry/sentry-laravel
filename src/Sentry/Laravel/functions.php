<?php

namespace Sentry\Laravel;

use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;

/**
 * Execute the given callable while wrapping it in a span added to the current transaction.
 *
 * If there is currently no transaction active this is a no-op.
 *
 * @param callable                    $toMeasure The callable that is going to be measured
 * @param \Sentry\Tracing\SpanContext $context   The context of the span to be created
 *
 * @return mixed
 */
function measure(callable $toMeasure, SpanContext $context)
{
    $hub = SentrySdk::getCurrentHub();

    $parentSpan = $hub->getSpan();

    // If there is no span set on the hub, there is no transaction
    // active currently. If that is the case we don't create a unused
    // span and we immediately execute the callable and return the result
    if ($parentSpan === null) {
        return $toMeasure();
    }

    $span = $parentSpan->startChild($context);

    // Set the new child span as the current span on the hub so
    // that if the callable also generates it's own spans they are
    // going to be nexted under this span instead of our parent span.
    $hub->setSpan($span);

    try {
        return $toMeasure();
    } finally {
        $span->finish();

        // Revert the current span back to the parent span
        // This ensures that after we are done next spans are
        // correctly nested under the parent span as is expected
        $hub->setSpan($parentSpan);
    }
}
