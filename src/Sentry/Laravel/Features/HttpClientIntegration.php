<?php

namespace Sentry\Laravel\Features;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Sentry\Laravel\Features\Concerns\TracksPushedScopesAndSpans;
use Sentry\Laravel\Util\WorksWithUris;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;

class HttpClientIntegration extends Feature
{
    use TracksPushedScopesAndSpans, WorksWithUris;

    public function isApplicable(): bool
    {
        return $this->isTracingFeatureEnabled('http_client_requests');
    }

    public function onBoot(Dispatcher $events): void
    {
        $events->listen(RequestSending::class, [$this, 'handleRequestSendingHandler']);
        $events->listen(ResponseReceived::class, [$this, 'handleResponseReceivedHandler']);
        $events->listen(ConnectionFailed::class, [$this, 'handleConnectionFailedHandler']);
    }

    public function handleRequestSendingHandler(RequestSending $event): void
    {
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        // If there is no tracing span active there is no need to handle the event
        if ($parentSpan === null) {
            return;
        }

        $context = new SpanContext;

        $fullUri = $this->getFullUri($event->request->url());
        $partialUri = $this->getPartialUri($fullUri);

        $context->setOp('http.client');
        $context->setDescription($event->request->method() . ' ' . $partialUri);
        $context->setData([
            'url' => $partialUri,
            'http.request.method' => $event->request->method(),
            'http.query' => $fullUri->getQuery(),
            'http.fragment' => $fullUri->getFragment(),
        ]);

        $this->pushSpan($parentSpan->startChild($context));
    }

    public function handleResponseReceivedHandler(ResponseReceived $event): void
    {
        $span = $this->maybePopSpan();

        if ($span !== null) {
            $span->finish();
            $span->setHttpStatus($event->response->status());
        }
    }

    public function handleConnectionFailedHandler(ConnectionFailed $event): void
    {
        $span = $this->maybePopSpan();

        if ($span !== null) {
            $span->finish();
            $span->setStatus(SpanStatus::internalError());
        }
    }
}
