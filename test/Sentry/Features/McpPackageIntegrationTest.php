<?php

namespace Sentry\Laravel\Tests\Features;

use Laravel\Mcp\Events\SessionInitialized;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\Methods\GetPrompt;
use Laravel\Mcp\Server\Methods\ReadResource;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Sentry\Laravel\Features\McpPackageIntegration;
use Sentry\Laravel\Tests\TestCase;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;

class McpPackageIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Server::class)) {
            $this->markTestSkipped('The laravel/mcp package is not installed.');
        }

        parent::setUp();
    }

    public function testToolCallSpanIsRecorded(): void
    {
        $transaction = $this->startTransaction();

        $callTool = $this->app->make(CallTool::class);
        $request = new JsonRpcRequest(1, 'tools/call', ['name' => 'get_weather', 'arguments' => ['location' => 'Dülmen, Germany']]);
        $context = $this->createServerContext();

        try {
            $callTool->handle($request, $context);
        } catch (\Throwable $e) {
            // Tool doesn't exist in context, but span should still be recorded
        }

        $spans = $transaction->getSpanRecorder()->getSpans();
        $mcpSpan = $this->findSpanByOp($spans, 'mcp.server');

        $this->assertNotNull($mcpSpan, 'MCP span should be recorded');
        $this->assertEquals('mcp.server', $mcpSpan->getOp());
        $this->assertEquals('tools/call get_weather', $mcpSpan->getDescription());

        $data = $mcpSpan->getData();
        $this->assertEquals('tools/call', $data['mcp.method.name']);
        $this->assertEquals('get_weather', $data['mcp.tool.name']);
        $this->assertEquals('Dülmen, Germany', $data['mcp.request.argument.location']);
        $this->assertEquals('2.0', $data['network.protocol.version']);
        $this->assertEquals('1', $data['mcp.request.id']);
        $this->assertEquals('Test Server', $data['mcp.server.name']);
        $this->assertEquals('1.0.0', $data['mcp.server.version']);
    }

    public function testPromptGetSpanIsRecorded(): void
    {
        $transaction = $this->startTransaction();

        $getPrompt = $this->app->make(GetPrompt::class);
        $request = new JsonRpcRequest(2, 'prompts/get', ['name' => 'analyze-code', 'arguments' => ['language' => 'php']]);
        $context = $this->createServerContext();

        try {
            $getPrompt->handle($request, $context);
        } catch (\Throwable $e) {
            // Prompt doesn't exist in context, but span should still be recorded
        }

        $spans = $transaction->getSpanRecorder()->getSpans();
        $mcpSpan = $this->findSpanByOp($spans, 'mcp.server');

        $this->assertNotNull($mcpSpan, 'MCP span should be recorded');
        $this->assertEquals('prompts/get analyze-code', $mcpSpan->getDescription());

        $data = $mcpSpan->getData();
        $this->assertEquals('prompts/get', $data['mcp.method.name']);
        $this->assertEquals('analyze-code', $data['mcp.prompt.name']);
        $this->assertEquals('php', $data['mcp.request.argument.language']);
    }

    public function testResourceReadSpanIsRecorded(): void
    {
        $transaction = $this->startTransaction();

        $readResource = $this->app->make(ReadResource::class);
        $request = new JsonRpcRequest(3, 'resources/read', ['uri' => 'file:///path/to/file']);
        $context = $this->createServerContext();

        try {
            $readResource->handle($request, $context);
        } catch (\Throwable $e) {
            // Resource doesn't exist in context, but span should still be recorded
        }

        $spans = $transaction->getSpanRecorder()->getSpans();
        $mcpSpan = $this->findSpanByOp($spans, 'mcp.server');

        $this->assertNotNull($mcpSpan, 'MCP span should be recorded');
        $this->assertEquals('resources/read file:///path/to/file', $mcpSpan->getDescription());

        $data = $mcpSpan->getData();
        $this->assertEquals('resources/read', $data['mcp.method.name']);
        $this->assertEquals('file:///path/to/file', $data['mcp.resource.uri']);
    }

    public function testInitializeSpanIsRecordedFromSessionEvent(): void
    {
        if (!class_exists(SessionInitialized::class)) {
            $this->markTestSkipped(
                'The SessionInitialized event is not available in laravel/mcp'.
                ' package version below 0.5.7.'
            );
        }

        $transaction = $this->startTransaction();

        $this->dispatchLaravelEvent(new SessionInitialized(
            'test-session-123',
            ['name' => 'claude-desktop', 'version' => '1.0.0', 'title' => 'Claude Desktop'],
            '2024-11-05',
            []
        ));

        $spans = $transaction->getSpanRecorder()->getSpans();
        $mcpSpan = $this->findSpanByOp($spans, 'mcp.server');

        $this->assertNotNull($mcpSpan, 'Initialize span should be recorded');
        $this->assertEquals('initialize', $mcpSpan->getDescription());

        $data = $mcpSpan->getData();
        $this->assertEquals('initialize', $data['mcp.method.name']);
        $this->assertEquals('test-session-123', $data['mcp.session.id']);
        $this->assertEquals('claude-desktop', $data['mcp.client.name']);
        $this->assertEquals('1.0.0', $data['mcp.client.version']);
        $this->assertEquals('2024-11-05', $data['mcp.protocol.version']);
    }

    public function testSessionDataIsPropagatedToSubsequentSpans(): void
    {
        if (!class_exists(SessionInitialized::class)) {
            $this->markTestSkipped(
                'The SessionInitialized event is not available in laravel/mcp'.
                ' package version below 0.5.7.'
            );
        }

        $transaction = $this->startTransaction();

        // First, fire the session initialized event
        $this->dispatchLaravelEvent(new SessionInitialized(
            'session-abc',
            ['name' => 'claude', 'version' => '2.0.0'],
            '2024-11-05',
            []
        ));

        // Then make a tool call with the same session ID
        $callTool = $this->app->make(CallTool::class);
        $request = new JsonRpcRequest(1, 'tools/call', ['name' => 'test_tool'], 'session-abc');
        $context = $this->createServerContext();

        try {
            $callTool->handle($request, $context);
        } catch (\Throwable $e) {
            // Expected
        }

        $spans = $transaction->getSpanRecorder()->getSpans();
        $mcpSpans = $this->findAllSpansByOp($spans, 'mcp.server');

        // Should have 2 spans: initialize + tool call
        $this->assertCount(2, $mcpSpans);

        // The tool call span should have session data
        $toolSpan = $mcpSpans[1];
        $data = $toolSpan->getData();
        $this->assertEquals('session-abc', $data['mcp.session.id']);
        $this->assertEquals('claude', $data['mcp.client.name']);
        $this->assertEquals('2.0.0', $data['mcp.client.version']);
        $this->assertEquals('2024-11-05', $data['mcp.protocol.version']);
    }

    public function testSpanHasCorrectOrigin(): void
    {
        $transaction = $this->startTransaction();

        $callTool = $this->app->make(CallTool::class);
        $request = new JsonRpcRequest(1, 'tools/call', ['name' => 'test']);
        $context = $this->createServerContext();

        try {
            $callTool->handle($request, $context);
        } catch (\Throwable $e) {
            // Expected
        }

        $spans = $transaction->getSpanRecorder()->getSpans();
        $mcpSpan = $this->findSpanByOp($spans, 'mcp.server');

        $this->assertNotNull($mcpSpan);
        $this->assertEquals('auto.mcp.server.laravel', $mcpSpan->getOrigin());
    }

    public function testSpanSetsErrorStatusOnException(): void
    {
        $transaction = $this->startTransaction();

        $callTool = $this->app->make(CallTool::class);
        $request = new JsonRpcRequest(1, 'tools/call', ['name' => 'nonexistent_tool']);
        $context = $this->createServerContext();

        try {
            $callTool->handle($request, $context);
        } catch (\Throwable $e) {
            // Expected - tool not found
        }

        $spans = $transaction->getSpanRecorder()->getSpans();
        $mcpSpan = $this->findSpanByOp($spans, 'mcp.server');

        $this->assertNotNull($mcpSpan);
        $this->assertEquals(SpanStatus::internalError(), $mcpSpan->getStatus());
    }

    public function testNoSpanIsRecordedWhenTracingIsDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.mcp' => false,
        ]);

        $transaction = $this->startTransaction();

        $callTool = $this->app->make(CallTool::class);
        $request = new JsonRpcRequest(1, 'tools/call', ['name' => 'test']);
        $context = $this->createServerContext();

        try {
            $callTool->handle($request, $context);
        } catch (\Throwable $e) {
            // Expected
        }

        $spans = $transaction->getSpanRecorder()->getSpans();
        $mcpSpan = $this->findSpanByOp($spans, 'mcp.server');

        $this->assertNull($mcpSpan, 'No MCP span should be recorded when tracing is disabled');
    }

    public function testTransportTypeAttributesForCliContext(): void
    {
        $transaction = $this->startTransaction();

        $callTool = $this->app->make(CallTool::class);
        $request = new JsonRpcRequest(1, 'tools/call', ['name' => 'test']);
        $context = $this->createServerContext();

        try {
            $callTool->handle($request, $context);
        } catch (\Throwable $e) {
            // Expected
        }

        $spans = $transaction->getSpanRecorder()->getSpans();
        $mcpSpan = $this->findSpanByOp($spans, 'mcp.server');

        $this->assertNotNull($mcpSpan);

        $data = $mcpSpan->getData();

        $this->assertEquals('stdio', $data['mcp.transport']);
        $this->assertEquals('pipe', $data['network.transport']);
    }

    /**
     * Create a minimal ServerContext for testing.
     */
    private function createServerContext(): ServerContext
    {
        return new ServerContext(
            ['2026-02-16'],
            [],
            'Test Server',
            '1.0.0',
            'Test instructions',
            50,
            15,
            [],
            [],
            []
        );
    }

    /**
     * Find the first span with the given op.
     *
     * @param \Sentry\Tracing\Span[] $spans
     * @param string $op
     */
    private function findSpanByOp(array $spans, string $op): ?Span
    {
        foreach ($spans as $span) {
            if ($span->getOp() === $op) {
                return $span;
            }
        }

        return null;
    }

    public function testGeneratorFullyConsumedFinishesSpanAndCleansUpStack(): void
    {
        $transaction = $this->startTransaction();
        $integration = $this->app->make(McpPackageIntegration::class);

        $inner = new class {
            public function handle($request, $context): \Generator
            {
                yield (object)['value' => 'item1'];
                yield (object)['value' => 'item2'];
            }
        };

        $request = new JsonRpcRequest(1, 'tools/call', ['name' => 'test']);
        $context = $this->createServerContext();

        $result = $integration->instrumentMethodHandle($inner, $request, $context);

        $this->assertInstanceOf(\Generator::class, $result);

        // Fully consume the generator
        $items = [];
        foreach ($result as $item) {
            $items[] = $item;
        }

        $this->assertCount(2, $items);

        // Span should be finished and stack should be clean
        $this->assertSpanStackIsEmpty($integration);

        // Hub should be back to the transaction
        $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan());

        // The MCP span should be recorded and finished
        $spans = $transaction->getSpanRecorder()->getSpans();
        $mcpSpan = $this->findSpanByOp($spans, 'mcp.server');
        $this->assertNotNull($mcpSpan, 'MCP span should be recorded');
        $this->assertNotNull($mcpSpan->getEndTimestamp(), 'Span should be finished');
    }

    public function testGeneratorNeverConsumedDoesNotLeakSpanStack(): void
    {
        $transaction = $this->startTransaction();
        $integration = $this->app->make(McpPackageIntegration::class);

        $inner = new class {
            public function handle($request, $context): \Generator
            {
                yield (object)['value' => 'item1'];
                yield (object)['value' => 'item2'];
            }
        };

        $request = new JsonRpcRequest(1, 'tools/call', ['name' => 'test']);
        $context = $this->createServerContext();

        $result = $integration->instrumentMethodHandle($inner, $request, $context);

        $this->assertInstanceOf(\Generator::class, $result);

        // Never consume the generator, just destroy it
        unset($result);

        // Stack should be clean — no memory leak
        $this->assertSpanStackIsEmpty($integration);

        // Hub should be back to the transaction
        $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan());
    }

    public function testGeneratorPartiallyConsumedCleansUpSpanOnDestruction(): void
    {
        $transaction = $this->startTransaction();
        $integration = $this->app->make(McpPackageIntegration::class);

        $inner = new class {
            public function handle($request, $context): \Generator
            {
                yield (object)['value' => 'item1'];
                yield (object)['value' => 'item2'];
                yield (object)['value' => 'item3'];
            }
        };

        $request = new JsonRpcRequest(1, 'tools/call', ['name' => 'test']);
        $context = $this->createServerContext();

        $result = $integration->instrumentMethodHandle($inner, $request, $context);

        $this->assertInstanceOf(\Generator::class, $result);

        // Partially consume the generator (only the first item)
        $result->current();

        // Destroy the generator while partially consumed — PHP's GC will
        // trigger the finally block because the generator is in GEN_SUSPENDED state
        unset($result);

        // Stack should be clean after GC
        $this->assertSpanStackIsEmpty($integration);

        // Hub should be back to the transaction
        $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan());

        // The MCP span should have been finished with deadline_exceeded status
        $spans = $transaction->getSpanRecorder()->getSpans();
        $mcpSpan = $this->findSpanByOp($spans, 'mcp.server');
        $this->assertNotNull($mcpSpan, 'MCP span should be recorded');
        $this->assertEquals(SpanStatus::deadlineExceeded(), $mcpSpan->getStatus());
    }

    public function testMultipleUnconsumedGeneratorsDoNotAccumulateOnStack(): void
    {
        $transaction = $this->startTransaction();
        $integration = $this->app->make(McpPackageIntegration::class);

        $inner = new class {
            public function handle($request, $context): \Generator
            {
                yield (object)['value' => 'item'];
            }
        };

        $request = new JsonRpcRequest(1, 'tools/call', ['name' => 'test']);
        $context = $this->createServerContext();

        // Simulate many calls where generators are never consumed —
        // this is the exact memory leak scenario from the bug report
        for ($i = 0; $i < 10; $i++) {
            $result = $integration->instrumentMethodHandle($inner, $request, $context);
            unset($result);
        }

        // Stack should still be empty after all iterations
        $this->assertSpanStackIsEmpty($integration);

        // Hub should be back to the transaction
        $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan());
    }

    /**
     * Assert that the integration's internal span stacks are empty.
     */
    private function assertSpanStackIsEmpty(McpPackageIntegration $integration): void
    {
        $currentStack = new \ReflectionProperty($integration, 'currentSpanStack');
        $currentStack->setAccessible(true);
        $this->assertEmpty($currentStack->getValue($integration), 'currentSpanStack should be empty');

        $parentStack = new \ReflectionProperty($integration, 'parentSpanStack');
        $parentStack->setAccessible(true);
        $this->assertEmpty($parentStack->getValue($integration), 'parentSpanStack should be empty');
    }

    /**
     * Find all spans with the given op.
     *
     * @param \Sentry\Tracing\Span[] $spans
     * @param string $op
     *
     * @return \Sentry\Tracing\Span[]
     */
    private function findAllSpansByOp(array $spans, string $op): array
    {
        $result = [];
        foreach ($spans as $span) {
            if ($span->getOp() === $op) {
                $result[] = $span;
            }
        }

        return $result;
    }
}
