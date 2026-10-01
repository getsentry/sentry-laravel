<?php

namespace Sentry\Laravel\Features;

use GuzzleHttp\Psr7\Uri;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Factory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Sentry\Breadcrumb;
use Sentry\DataCollection\DataCollectionPolicy;
use Sentry\DataCollection\HttpCookieCollector;
use Sentry\DataCollection\HttpHeaderCollector;
use Sentry\DataCollection\HttpMessageType;
use Sentry\DataCollection\HttpUrlCollector;
use Sentry\Laravel\Features\Concerns\ResolvesEventOrigin;
use Sentry\Laravel\Features\Concerns\TracksPushedScopesAndSpans;
use Sentry\Laravel\Integration;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use function Sentry\getBaggage;
use function Sentry\getTraceparent;

class HttpClientIntegration extends Feature
{
    use ResolvesEventOrigin;
    use TracksPushedScopesAndSpans;

    private const FEATURE_KEY = 'http_client_requests';

    /**
     * Indicates if we should trace the origin of the HTTP client requests.
     *
     * @var bool|null
     */
    private $traceHttpClientRequestsOrigin;

    /**
     * The threshold in milliseconds for HTTP client requests to resolve their origin.
     *
     * @var int|null
     */
    private $traceHttpClientRequestsOriginThresholdMs;

    public function isApplicable(): bool
    {
        return $this->isTracingFeatureEnabled(self::FEATURE_KEY)
            || $this->isBreadcrumbFeatureEnabled(self::FEATURE_KEY);
    }

    public function onBoot(Dispatcher $events, Factory $factory): void
    {
        if ($this->isTracingFeatureEnabled(self::FEATURE_KEY)) {
            $events->listen(RequestSending::class, [$this, 'handleRequestSendingHandlerForTracing']);
            $events->listen(ResponseReceived::class, [$this, 'handleResponseReceivedHandlerForTracing']);
            $events->listen(ConnectionFailed::class, [$this, 'handleConnectionFailedHandlerForTracing']);

            // The `globalRequestMiddleware` functionality was introduced in Laravel 10.14
            if (method_exists($factory, 'globalRequestMiddleware')) {
                $factory->globalRequestMiddleware([$this, 'attachTracingHeadersToRequest']);
            }
        }

        if ($this->isBreadcrumbFeatureEnabled(self::FEATURE_KEY)) {
            $events->listen(ResponseReceived::class, [$this, 'handleResponseReceivedHandlerForBreadcrumb']);
            $events->listen(ConnectionFailed::class, [$this, 'handleConnectionFailedHandlerForBreadcrumb']);
        }
    }

    public function attachTracingHeadersToRequest(RequestInterface $request)
    {
        if ($this->shouldAttachTracingHeaders($request)) {
            return $request
                ->withHeader('baggage', getBaggage())
                ->withHeader('sentry-trace', getTraceparent());
        }

        return $request;
    }

    public function handleRequestSendingHandlerForTracing(RequestSending $event): void
    {
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        // If there is no sampled span there is no need to handle the event
        if ($parentSpan === null || !$parentSpan->getSampled()) {
            return;
        }

        $policy = DataCollectionPolicy::fromHub(SentrySdk::getCurrentHub());
        $request = $event->request->toPsrRequest();
        $fullUri = $this->getFullUri($event->request->url());
        $partialUri = $this->getPartialUri($fullUri);

        $this->pushSpan(
            $parentSpan->startChild(
                SpanContext::make()
                    ->setOp('http.client')
                    ->setData(array_merge([
                        'url' => $partialUri,
                        // See: https://develop.sentry.dev/sdk/performance/span-data-conventions/#http
                        'http.fragment' => $fullUri->getFragment(),
                        'http.request.method' => $event->request->method(),
                        'http.request.body.size' => $request->getBody()->getSize(),
                    ], $this->collectUrlData($policy, $fullUri), $this->collectRequestHeaderData($policy, $request)))
                    ->setOrigin('auto.http.client')
                    ->setDescription($event->request->method() . ' ' . $partialUri)
            )
        );
    }

    public function handleResponseReceivedHandlerForTracing(ResponseReceived $event): void
    {
        $span = $this->maybePopSpan();

        if ($span !== null) {
            $policy = DataCollectionPolicy::fromHub(SentrySdk::getCurrentHub());
            $response = $event->response->toPsrResponse();

            $span->setData(array_merge($span->getData(), [
                // See: https://develop.sentry.dev/sdk/performance/span-data-conventions/#http
                'http.response.status_code' => $event->response->status(),
                'http.response.body.size' => $response->getBody()->getSize(),
            ], $this->collectResponseHeaderData($policy, $response)));

            $this->maybeAddRequestOriginToSpan($span);

            $span->setHttpStatus($event->response->status());
            $span->finish();
        }
    }

    public function handleConnectionFailedHandlerForTracing(ConnectionFailed $event): void
    {
        $span = $this->maybePopSpan();

        if ($span !== null) {
            $this->maybeAddRequestOriginToSpan($span);

            $span->setStatus(SpanStatus::internalError());
            $span->finish();
        }
    }

    public function handleResponseReceivedHandlerForBreadcrumb(ResponseReceived $event): void
    {
        $level = Breadcrumb::LEVEL_INFO;

        if ($event->response->clientError()) {
            $level = Breadcrumb::LEVEL_WARNING;
        } elseif ($event->response->serverError()) {
            $level = Breadcrumb::LEVEL_ERROR;
        }

        $fullUri = $this->getFullUri($event->request->url());

        Integration::addBreadcrumb(new Breadcrumb(
            $level,
            Breadcrumb::TYPE_HTTP,
            'http',
            null,
            array_merge([
                'url' => $this->getPartialUri($fullUri),
                // See: https://develop.sentry.dev/sdk/performance/span-data-conventions/#http
                'http.fragment' => $fullUri->getFragment(),
                'http.request.method' => $event->request->method(),
                'http.response.status_code' => $event->response->status(),
                'http.request.body.size' => $event->request->toPsrRequest()->getBody()->getSize(),
                'http.response.body.size' => $event->response->toPsrResponse()->getBody()->getSize(),
            ], $this->collectUrlData(DataCollectionPolicy::fromHub(SentrySdk::getCurrentHub()), $fullUri))
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
            array_merge([
                'url' => $this->getPartialUri($fullUri),
                // See: https://develop.sentry.dev/sdk/performance/span-data-conventions/#http
                'http.fragment' => $fullUri->getFragment(),
                'http.request.method' => $event->request->method(),
                'http.request.body.size' => $event->request->toPsrRequest()->getBody()->getSize(),
            ], $this->collectUrlData(DataCollectionPolicy::fromHub(SentrySdk::getCurrentHub()), $fullUri))
        ));
    }

    /**
     * @return array<string, string>
     */
    private function collectUrlData(DataCollectionPolicy $policy, UriInterface $uri): array
    {
        // The legacy options always collected the query string, even when it is empty
        if ($policy->isLegacyMode()) {
            return ['http.query' => $uri->getQuery()];
        }

        $data = [];

        $queryString = HttpUrlCollector::collectQueryString($policy, $uri->getQuery());
        if ($queryString !== null) {
            $data['http.query'] = $queryString;
        }

        $fullUrl = HttpUrlCollector::collect($policy, HttpMessageType::outgoingRequest(), $uri);
        if ($fullUrl !== null) {
            $data['url.full'] = $fullUrl;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function collectRequestHeaderData(DataCollectionPolicy $policy, RequestInterface $request): array
    {
        return $this->getHeaderData(
            'http.request.header',
            HttpHeaderCollector::collect($policy, HttpMessageType::outgoingRequest(), $request->getHeaders()),
            'http.request.header.cookie',
            HttpCookieCollector::collectPsr7Request($policy, HttpMessageType::outgoingRequest(), $request)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function collectResponseHeaderData(DataCollectionPolicy $policy, ResponseInterface $response): array
    {
        return $this->getHeaderData(
            'http.response.header',
            HttpHeaderCollector::collect($policy, HttpMessageType::incomingResponse(), $response->getHeaders()),
            'http.response.header.set_cookie',
            HttpCookieCollector::collectPsr7Response($policy, HttpMessageType::incomingResponse(), $response)
        );
    }

    /**
     * @param array<array-key, string[]>|null     $headers
     * @param array<array-key, mixed>|string|null $cookies Cookies grouped by name, or `[Filtered]` if they could not be parsed
     *
     * @return array<string, mixed>
     */
    private function getHeaderData(string $headerPrefix, ?array $headers, string $cookiePrefix, $cookies): array
    {
        $data = [];

        foreach ($headers ?? [] as $name => $values) {
            $data[$headerPrefix . '.' . strtolower((string)$name)] = $values;
        }

        if (is_string($cookies)) {
            $data[$cookiePrefix] = $cookies;
        } elseif (is_array($cookies)) {
            foreach ($cookies as $name => $value) {
                $data[$cookiePrefix . '.' . $name] = $value;
            }
        }

        return $data;
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
        return (string)Uri::fromParts([
            'scheme' => $uri->getScheme(),
            'host' => $uri->getHost(),
            'port' => $uri->getPort(),
            'path' => $uri->getPath(),
        ]);
    }

    private function shouldAttachTracingHeaders(RequestInterface $request): bool
    {
        $client = SentrySdk::getCurrentHub()->getClient();
        if ($client === null) {
            return false;
        }

        $sdkOptions = $client->getOptions();

        // Check if the request destination is allow listed in the trace_propagation_targets option.
        return $sdkOptions->getTracePropagationTargets() === null
            || in_array($request->getUri()->getHost(), $sdkOptions->getTracePropagationTargets());
    }

    /**
     * Add the code location that made the HTTP client request to the span if the request was slower than the threshold.
     */
    private function maybeAddRequestOriginToSpan(Span $span): void
    {
        if (!$this->shouldTraceHttpClientRequestsOrigin()) {
            return;
        }

        $duration = ($span->getEndTimestamp() ?? microtime(true)) - $span->getStartTimestamp();
        $durationMs = $duration * 1000;

        if ($durationMs < $this->getHttpClientRequestsOriginThresholdMs()) {
            return;
        }

        $requestOrigin = $this->resolveEventOrigin();

        if ($requestOrigin !== null) {
            $span->setData(array_merge($span->getData(), $requestOrigin));
        }
    }

    /**
     * Indicates if we should trace the origin of the HTTP client requests.
     */
    private function shouldTraceHttpClientRequestsOrigin(): bool
    {
        if ($this->traceHttpClientRequestsOrigin === null) {
            $tracingConfig = $this->getUserConfig()['tracing'] ?? [];

            $this->traceHttpClientRequestsOrigin = ($tracingConfig['http_client_requests_origin'] ?? true) === true;
        }

        return $this->traceHttpClientRequestsOrigin;
    }

    /**
     * Get the threshold in milliseconds for HTTP client requests to resolve their origin.
     */
    private function getHttpClientRequestsOriginThresholdMs(): int
    {
        if ($this->traceHttpClientRequestsOriginThresholdMs === null) {
            $tracingConfig = $this->getUserConfig()['tracing'] ?? [];

            $thresholdMs = $tracingConfig['http_client_requests_origin_threshold_ms'] ?? null;

            $this->traceHttpClientRequestsOriginThresholdMs = is_numeric($thresholdMs) ? (int)$thresholdMs : 250;
        }

        return $this->traceHttpClientRequestsOriginThresholdMs;
    }
}
