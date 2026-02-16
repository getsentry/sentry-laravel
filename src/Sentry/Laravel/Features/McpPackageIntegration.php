<?php

namespace Sentry\Laravel\Features;

use Generator;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Mcp\Events\SessionInitialized;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\Methods\GetPrompt;
use Laravel\Mcp\Server\Methods\ReadResource;
use Sentry\Laravel\Features\Concerns\TracksPushedScopesAndSpans;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;

class McpPackageIntegration extends Feature
{
    use TracksPushedScopesAndSpans;

    private const FEATURE_KEY = 'mcp';

    /**
     * Session metadata stored from SessionInitialized events.
     *
     * @var array<string, array<string, mixed>>
     */
    private $sessions = [];

    public function isApplicable(): bool
    {
        return class_exists(Server::class);
    }

    public function onBoot(Dispatcher $events): void
    {
        if (!$this->isTracingFeatureEnabled(self::FEATURE_KEY)) {
            return;
        }

        $this->registerMethodExtensions();

        $events->listen(SessionInitialized::class, [$this, 'handleSessionInitialized']);
    }

    /**
     * Handle the SessionInitialized event to store session metadata and create an initialize span.
     *
     * @param \Laravel\Mcp\Events\SessionInitialized $event
     */
    public function handleSessionInitialized(SessionInitialized $event): void
    {
        $sessionData = [
            'mcp.client.name' => $event->clientName(),
            'mcp.client.version' => $event->clientVersion(),
            'mcp.protocol.version' => $event->protocolVersion,
        ];

        if (method_exists($event, 'clientTitle')) {
            $sessionData['mcp.client.title'] = $event->clientTitle();
        }

        $this->sessions[$event->sessionId] = $sessionData;

        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        if ($parentSpan === null || !$parentSpan->getSampled()) {
            return;
        }

        $data = array_merge(
            $this->getCommonSpanData(null, $event->sessionId),
            array_filter($sessionData),
            ['mcp.method.name' => 'initialize']
        );

        $span = $parentSpan->startChild(
            SpanContext::make()
                ->setOp('mcp.server')
                ->setOrigin('auto.mcp.server.laravel')
                ->setDescription('initialize')
                ->setData($data)
        );

        $span->finish();
    }

    /**
     * Register container extensions for MCP method classes to wrap them with Sentry instrumentation.
     */
    private function registerMethodExtensions(): void
    {
        $self = $this;
        $container = $this->container();

        $methodClasses = [
            CallTool::class,
            GetPrompt::class,
            ReadResource::class,
        ];

        foreach ($methodClasses as $methodClass) {
            if (!class_exists($methodClass)) {
                continue;
            }

            $container->extend($methodClass, function ($method) use ($self) {
                return $self->createMethodWrapper($method);
            });
        }
    }

    /**
     * Create a wrapper around an MCP method that adds Sentry instrumentation.
     *
     * @param object $inner The original method instance
     *
     * @return object The wrapped method
     */
    public function createMethodWrapper($inner)
    {
        $integration = $this;

        return new class($inner, $integration) {
            private object $inner;

            private McpPackageIntegration $integration;

            public function __construct($inner, McpPackageIntegration $integration)
            {
                $this->inner = $inner;
                $this->integration = $integration;
            }

            public function handle(Server\Transport\JsonRpcRequest $request, Server\ServerContext $context): Server\Transport\JsonRpcResponse
            {
                return $this->integration->instrumentMethodHandle($this->inner, $request, $context);
            }
        };
    }

    public function instrumentMethodHandle($inner, Server\Transport\JsonRpcRequest $request, Server\ServerContext $context): mixed
    {
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        if ($parentSpan === null || !$parentSpan->getSampled()) {
            return $inner->handle($request, $context);
        }

        $spanAttributes = $this->buildSpanAttributes($request, $context);

        $span = $parentSpan->startChild(
            SpanContext::make()
                ->setOp('mcp.server')
                ->setOrigin('auto.mcp.server.laravel')
                ->setDescription($spanAttributes['name'])
                ->setData($spanAttributes['data'])
        );

        $this->pushSpan($span);

        try {
            $result = $inner->handle($request, $context);

            if ($result instanceof Generator) {
                // Pop the span from the stack before returning the generator. The generator
                // body will re-push it when iteration starts. This ensures that if the
                // generator is never consumed (GEN_CREATED state), the span stack doesn't
                // grow — PHP won't execute the generator body (including any finally block)
                // for generators that were never started.
                $this->maybePopSpan();

                return $this->wrapGeneratorResult($result, $request, $span);
            }

            $this->addResultDataToCurrentSpan($request, $result);
            $this->maybeFinishSpan();

            return $result;
        } catch (\Throwable $e) {
            $this->maybeFinishSpan(SpanStatus::internalError());
            throw $e;
        }
    }

    private function wrapGeneratorResult(Generator $generator, object $request, Span $span): Generator
    {
        // Re-push the span onto the stack now that the generator has actually started.
        // This only executes when the generator is iterated (GEN_SUSPENDED state),
        // which guarantees that the finally block below will run during GC if the
        // generator is later abandoned.
        $this->pushSpan($span);

        $lastItem = null;
        $spanFinished = false;

        try {
            foreach ($generator as $item) {
                $lastItem = $item;
                yield $item;
            }

            if ($lastItem !== null) {
                $this->addResultDataToCurrentSpan($request, $lastItem);
            }

            $this->maybeFinishSpan();
            $spanFinished = true;
        } catch (\Throwable $e) {
            $this->maybeFinishSpan(SpanStatus::internalError());
            $spanFinished = true;
            throw $e;
        } finally {
            // If the generator is destroyed without being fully consumed (e.g. the client
            // stops iterating midway), we still need to pop the span from the stack to
            // prevent a memory leak in long-lived server processes.
            if (!$spanFinished) {
                $this->maybeFinishSpan(SpanStatus::deadlineExceeded());
            }
        }
    }

    /**
     * Build span attributes based on the MCP request.
     *
     * @param object $request JsonRpcRequest
     * @param object $context ServerContext
     *
     * @return array{name: string, data: array<string, mixed>}
     */
    private function buildSpanAttributes(object $request, object $context): array
    {
        $method = $request->method;

        $data = $this->getCommonSpanData($request, $request->sessionId);
        $data['mcp.method.name'] = $method;
        $data['mcp.server.name'] = $context->serverName;
        $data['mcp.server.version'] = $context->serverVersion;

        // Add session data if available
        $sessionId = $request->sessionId;
        if ($sessionId !== null && isset($this->sessions[$sessionId])) {
            $data = array_merge($data, array_filter($this->sessions[$sessionId]));
        }

        $name = $method;

        switch ($method) {
            case 'tools/call':
                $toolName = $request->params['name'] ?? null;
                if ($toolName !== null) {
                    $name = "tools/call {$toolName}";
                    $data['mcp.tool.name'] = $toolName;
                }
                $this->addArgumentsToData($data, $request->params['arguments'] ?? []);
                break;

            case 'prompts/get':
                $promptName = $request->params['name'] ?? null;
                if ($promptName !== null) {
                    $name = "prompts/get {$promptName}";
                    $data['mcp.prompt.name'] = $promptName;
                }
                $this->addArgumentsToData($data, $request->params['arguments'] ?? []);
                break;

            case 'resources/read':
                $resourceUri = $request->params['uri'] ?? null;
                if ($resourceUri !== null) {
                    $name = "resources/read {$resourceUri}";
                    $data['mcp.resource.uri'] = $resourceUri;
                }
                break;
        }

        return [
            'name' => $name,
            'data' => $data,
        ];
    }

    /**
     * Add MCP request arguments to span data with the appropriate prefix.
     *
     * @param array<string, mixed> $data
     * @param mixed $arguments
     */
    private function addArgumentsToData(array &$data, $arguments): void
    {
        if (!is_array($arguments)) {
            return;
        }

        foreach ($arguments as $key => $value) {
            $data["mcp.request.argument.{$key}"] = is_string($value) ? $value : json_encode($value);
        }
    }

    /**
     * Get common span data for MCP operations.
     *
     * @param object|null $request
     * @param string|null $sessionId
     *
     * @return array<string, mixed>
     */
    private function getCommonSpanData(?object $request, ?string $sessionId): array
    {
        $isConsole = app()->runningInConsole();

        $data = [
            'mcp.transport' => $isConsole ? 'stdio' : 'http',
            'network.transport' => $isConsole ? 'pipe' : 'tcp',
            'network.protocol.version' => '2.0',
        ];

        if ($request !== null && isset($request->id)) {
            $data['mcp.request.id'] = (string) $request->id;
        }

        if ($sessionId !== null && $sessionId !== '') {
            $data['mcp.session.id'] = $sessionId;
        }

        return $data;
    }

    private function addResultDataToCurrentSpan(object $request, object $result): void
    {
        if ($request->method !== 'tools/call') {
            return;
        }

        if (!method_exists($result, 'toArray')) {
            return;
        }

        $span = SentrySdk::getCurrentHub()->getSpan();

        if ($span === null) {
            return;
        }

        $responseData = $result->toArray();
        $resultData = $responseData['result'] ?? [];

        if (!is_array($resultData)) {
            return;
        }

        $existingData = $span->getData();

        if (array_key_exists('isError', $resultData)) {
            $existingData['mcp.tool.result.is_error'] = (bool) $resultData['isError'];

            if ($resultData['isError'] === true) {
                $span->setStatus(SpanStatus::internalError());
            }
        }

        if (isset($resultData['content']) && is_array($resultData['content'])) {
            $existingData['mcp.tool.result.content_count'] = count($resultData['content']);
            $existingData['mcp.tool.result.content'] = json_encode($resultData['content']);
        }

        $span->setData($existingData);
    }
}
