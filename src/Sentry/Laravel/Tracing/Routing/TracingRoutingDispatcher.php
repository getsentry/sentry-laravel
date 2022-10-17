<?php

namespace Sentry\Laravel\Tracing\Routing;

use Illuminate\Routing\Route;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;

abstract class TracingRoutingDispatcher
{
    protected function wrapRouteDispatch(callable $dispatch, Route $route)
    {
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        // When there is no active transaction we can skip doing anything and just immediately return the callable
        if ($parentSpan === null) {
            return $dispatch();
        }

        $context = new SpanContext;
        $context->setOp('http.route');
        $context->setDescription($route->getActionName());

        $span = $parentSpan->startChild($context);

        SentrySdk::getCurrentHub()->setSpan($span);

        try {
            return $dispatch();
        } finally {
            $span->finish();

            SentrySdk::getCurrentHub()->setSpan($parentSpan);
        }
    }
}
