<?php

namespace Sentry\Laravel;

use Sentry\SentrySdk;
use Sentry\State\Scope;
use Sentry\Tracing\SpanContext;

/**
 * Execute the given callable while wrapping it in a span added as a child to the current transaction and active span.
 *
 * If there is no transaction active this is a no-op and the scope passed to the trace callable will be `null`.
 *
 * @param callable(\Sentry\State\Scope|null): mixed $trace   The callable that is going to be traced
 * @param \Sentry\Tracing\SpanContext               $context The context of the span to be created
 *
 * @return mixed
 */
function trace(callable $trace, SpanContext $context)
{
    return SentrySdk::getCurrentHub()->withScope(function (Scope $scope) use ($context, $trace) {
        $parentSpan = $scope->getSpan();

        // If there's no span set on the scope there is no transaction
        // active currently. If that is the case we don't create a unused
        // span and we immediately execute the callable and return the result
        if ($parentSpan === null) {
            return $trace(null);
        }

        $span = $parentSpan->startChild($context);

        $scope->setSpan($span);

        return $trace($scope);
    });
}
