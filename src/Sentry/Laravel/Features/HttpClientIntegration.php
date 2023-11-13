<?php

namespace Sentry\Laravel\Features;

use GuzzleHttp\Psr7\Uri;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Psr\Http\Message\UriInterface;
use Sentry\Breadcrumb;
use Sentry\Laravel\Features\Concerns\TracksPushedScopesAndSpans;
use Sentry\Laravel\Integration;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;

class HttpClientIntegration extends Feature
{
    use TracksPushedScopesAndSpans;

    private const FEATURE_KEY = 'http_client_requests';

    public function isApplicable(): bool
    {
        return $this->isTracingFeatureEnabled(self::FEATURE_KEY)
            || $this->isBreadcrumbFeatureEnabled(self::FEATURE_KEY);
    }

    public function onBoot(Dispatcher $events): void
    {
        if ($this->isTracingFeatureEnabled(self::FEATURE_KEY)) {
            $events->listen(RequestSending::class, [$this, 'handleRequestSendingHandlerForTracing']);
            $events->listen(ResponseReceived::class, [$this, 'handleResponseReceivedHandlerForTracing']);
            $events->listen(ConnectionFailed::class, [$this, 'handleConnectionFailedHandlerForTracing']);
        }

        if ($this->isBreadcrumbFeatureEnabled(self::FEATURE_KEY)) {
            $events->listen(ResponseReceived::class, [$this, 'handleResponseReceivedHandlerForBreadcrumb']);
            $events->listen(ConnectionFailed::class, [$this, 'handleConnectionFailedHandlerForBreadcrumb']);
        }
    }

    public function handleRequestSendingHandlerForTracing(RequestSending $event): void
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

    public function handleResponseReceivedHandlerForTracing(ResponseReceived $event): void
    {
        $span = $this->maybePopSpan();

        if ($span !== null) {
            $span->finish();
            $span->setHttpStatus($event->response->status());
        }
    }

    public function handleConnectionFailedHandlerForTracing(ConnectionFailed $event): void
    {
        $span = $this->maybePopSpan();

        if ($span !== null) {
            $span->finish();
            $span->setStatus(SpanStatus::internalError());
        }
    }

    public function handleResponseReceivedHandlerForBreadcrumb(ResponseReceived $event): void
    {
        $level = Breadcrumb::LEVEL_INFO;

        if ($event->response->failed()) {
            $level = Breadcrumb::LEVEL_ERROR;
        }

        $fullUri = $this->getFullUri($event->request->url());

        Integration::addBreadcrumb(new Breadcrumb(
            $level,
            Breadcrumb::TYPE_HTTP,
            'http',
            null,
            [
                'url' => $this->getPartialUri($fullUri),
                'http.request.method' => $event->request->method(),
                'http.response.status_code' => $event->response->status(),
                'http.query' => $fullUri->getQuery(),
                'http.fragment' => $fullUri->getFragment(),
                'http.request.body.size' => $event->request->toPsrRequest()->getBody()->getSize(),
                'http.response.body.size' => $event->response->toPsrResponse()->getBody()->getSize(),
            ]
        ));
    }

    public function handleConnectionFailedHandlerForBreadcrumb(ConnectionFailed $event): void
    {
        $fullUri = $this->getFullUri($event->request->url());

        Integration::addBreadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_ERROR,
            Breadcrumb::TYPE_HTTP,
            'http',
            null,
            [
                'url' => $this->getPartialUri($fullUri),
                'http.request.method' => $event->request->method(),
                'http.query' => $fullUri->getQuery(),
                'http.fragment' => $fullUri->getFragment(),
                'http.request.body.size' => $event->request->toPsrRequest()->getBody()->getSize(),
            ]
        ));
    }

    /**
     * Construct a full URI.
     *
     * @param string $url
     *
     * @return UriInterface
     */
    private function getFullUri(string $url): UriInterface
    {
        return new Uri($url);
    }

    /**
     * Construct a partial URI, excluding the authority, query and fragment parts.
     *
     * @param UriInterface $uri
     *
     * @return string
     */
    private function getPartialUri(UriInterface $uri): string
    {
        return (string) Uri::fromParts([
            'scheme' => $uri->getScheme(),
            'host' => $uri->getHost(),
            'port' => $uri->getPort(),
            'path' => $uri->getPath(),
        ]);
    }
}
