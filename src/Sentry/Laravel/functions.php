<?php

namespace Sentry\Laravel;

use Sentry\SentrySdk;
use Sentry\State\Scope;
use Sentry\Tracing\SpanContext;

/**
 * Execute the given callable while wrapping it in a span added as a child to the current transaction and active span.
 *
 * If there is no transaction active this is a no-op and the scope passed to the trace callable will be unused.
 *
 * @param callable(\Sentry\State\Scope): mixed $trace   The callable that is going to be traced
 * @param \Sentry\Tracing\SpanContext          $context The context of the span to be created
 *
 * @return mixed
 */
function trace(callable $trace, SpanContext $context)
{
    return SentrySdk::getCurrentHub()->withScope(function (Scope $scope) use ($context, $trace) {
        $parentSpan = $scope->getSpan();

        // If there's a span set on the scope there is a transaction
        // active currently. If that is the case we create a child span
        // and set it on the scope. Otherwise we only execute the callable
        if ($parentSpan !== null) {
            $span = $parentSpan->startChild($context);

            $scope->setSpan($span);
        }

        return $trace($scope);
    });
}
