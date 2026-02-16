<?php

/**
 * Since laravel/ai may not be installed as a dev dependency, we create stub
 * event/data classes that mirror the structure of the real Laravel AI SDK classes.
 * The AiIntegration uses `object` type hints and accesses properties dynamically,
 * so these stubs work transparently.
 */

// Stub classes in the Laravel\Ai\Events namespace so isApplicable() returns true.
// These must be defined before the test class so the class_exists check passes.
namespace Laravel\Ai\Events;

if (!class_exists(\Laravel\Ai\Events\PromptingAgent::class)) {
    class PromptingAgent
    {
        public string $invocationId;
        public object $prompt;

        public function __construct(string $invocationId, object $prompt)
        {
            $this->invocationId = $invocationId;
            $this->prompt = $prompt;
        }
    }
}

if (!class_exists(\Laravel\Ai\Events\AgentPrompted::class)) {
    class AgentPrompted
    {
        public string $invocationId;
        public object $prompt;
        public object $response;

        public function __construct(string $invocationId, object $prompt, object $response)
        {
            $this->invocationId = $invocationId;
            $this->prompt = $prompt;
            $this->response = $response;
        }
    }
}

if (!class_exists(\Laravel\Ai\Events\InvokingTool::class)) {
    class InvokingTool
    {
        public string $invocationId;
        public string $toolInvocationId;
        public object $agent;
        public object $tool;
        public array $arguments;

        public function __construct(string $invocationId, string $toolInvocationId, object $agent, object $tool, array $arguments)
        {
            $this->invocationId = $invocationId;
            $this->toolInvocationId = $toolInvocationId;
            $this->agent = $agent;
            $this->tool = $tool;
            $this->arguments = $arguments;
        }
    }
}

if (!class_exists(\Laravel\Ai\Events\ToolInvoked::class)) {
    class ToolInvoked
    {
        public string $invocationId;
        public string $toolInvocationId;
        public object $agent;
        public object $tool;
        public array $arguments;
        public $result;

        public function __construct(string $invocationId, string $toolInvocationId, object $agent, object $tool, array $arguments, $result)
        {
            $this->invocationId = $invocationId;
            $this->toolInvocationId = $toolInvocationId;
            $this->agent = $agent;
            $this->tool = $tool;
            $this->arguments = $arguments;
            $this->result = $result;
        }
    }
}

// Stub agent, tool, and data classes with predictable class names
namespace Sentry\Laravel\Tests\Features\AiStubs;

class TestAgent
{
    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    /** @return object[] */
    public function tools(): array
    {
        return [
            new WeatherLookup(),
        ];
    }
}

class TestAgentNoTools
{
    public function instructions(): string
    {
        return 'You are a simple agent.';
    }
}

class WeatherLookup
{
    public function name(): string
    {
        return 'WeatherLookup';
    }

    public function description(): string
    {
        return 'Looks up the current weather for a given location.';
    }
}

class DatabaseQuery
{
    public function name(): string
    {
        return 'DatabaseQuery';
    }

    public function description(): string
    {
        return 'Executes a database query.';
    }
}

class TestProvider
{
    public function driver(): string
    {
        return 'openai';
    }
}

class TestUsage
{
    public int $promptTokens;
    public int $completionTokens;
    public int $cacheReadInputTokens;
    public int $cacheWriteInputTokens;
    public int $reasoningTokens;

    public function __construct(
        int $promptTokens = 0,
        int $completionTokens = 0,
        int $cacheReadInputTokens = 0,
        int $cacheWriteInputTokens = 0,
        int $reasoningTokens = 0
    ) {
        $this->promptTokens = $promptTokens;
        $this->completionTokens = $completionTokens;
        $this->cacheReadInputTokens = $cacheReadInputTokens;
        $this->cacheWriteInputTokens = $cacheWriteInputTokens;
        $this->reasoningTokens = $reasoningTokens;
    }
}

class TestToolCall
{
    public string $name;
    public array $arguments;

    public function __construct(string $name, array $arguments = [])
    {
        $this->name = $name;
        $this->arguments = $arguments;
    }
}

class TestToolResult
{
    public string $name;
    public $result;

    public function __construct(string $name, $result)
    {
        $this->name = $name;
        $this->result = $result;
    }
}

// Now the actual test class
namespace Sentry\Laravel\Tests\Features;

use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Client\Response as HttpResponse;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolInvoked;
use Sentry\Laravel\Tests\Features\AiStubs\DatabaseQuery;
use Sentry\Laravel\Tests\Features\AiStubs\TestAgent;
use Sentry\Laravel\Tests\Features\AiStubs\TestAgentNoTools;
use Sentry\Laravel\Tests\Features\AiStubs\TestProvider;
use Sentry\Laravel\Tests\Features\AiStubs\TestToolCall;
use Sentry\Laravel\Tests\Features\AiStubs\TestToolResult;
use Sentry\Laravel\Tests\Features\AiStubs\TestUsage;
use Sentry\Laravel\Tests\Features\AiStubs\WeatherLookup;
use Sentry\Laravel\Tests\TestCase;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;

class AiIntegrationTest extends TestCase
{
    private const PROVIDER_URL = 'https://api.openai.com/v1';

    protected function setUp(): void
    {
        parent::setUp();

        // Set up the Prism provider config so the integration can resolve the URL
        config(['prism.providers.openai.url' => self::PROVIDER_URL]);
    }

    // ---- invoke_agent span tests ----

    public function testAgentSpanIsRecorded(): void
    {
        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponse();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-1', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-1', $prompt, $response));

        $spans = $transaction->getSpanRecorder()->getSpans();

        // Transaction + agent span + 1 chat span = 3
        $this->assertCount(3, $spans);

        /** @var Span $agentSpan */
        $agentSpan = $spans[1];

        $this->assertEquals('gen_ai.invoke_agent', $agentSpan->getOp());
        $this->assertEquals('invoke_agent TestAgent', $agentSpan->getDescription());
        $this->assertEquals(SpanStatus::ok(), $agentSpan->getStatus());
        $this->assertEquals('auto.ai.laravel', $agentSpan->getOrigin());

        $data = $agentSpan->getData();
        $this->assertEquals('invoke_agent', $data['gen_ai.operation.name']);
        $this->assertEquals('TestAgent', $data['gen_ai.agent.name']);
        $this->assertEquals('gpt-4o', $data['gen_ai.request.model']);
        $this->assertEquals('gpt-4o-2024-08-06', $data['gen_ai.response.model']);
    }

    public function testAgentSpanCapturesTokenUsage(): void
    {
        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponse(
            promptTokens: 100,
            completionTokens: 50,
            cacheReadInputTokens: 20,
            cacheWriteInputTokens: 10,
            reasoningTokens: 15
        );

        $this->dispatchLaravelEvent(new PromptingAgent('inv-2', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-2', $prompt, $response));

        $spans = $transaction->getSpanRecorder()->getSpans();
        $data = $spans[1]->getData();

        $this->assertEquals(100, $data['gen_ai.usage.input_tokens']);
        $this->assertEquals(50, $data['gen_ai.usage.output_tokens']);
        $this->assertEquals(150, $data['gen_ai.usage.total_tokens']);
        $this->assertEquals(20, $data['gen_ai.usage.input_tokens.cached']);
        $this->assertEquals(10, $data['gen_ai.usage.input_tokens.cache_write']);
        $this->assertEquals(15, $data['gen_ai.usage.output_tokens.reasoning']);
    }

    public function testAgentSpanCapturesToolDefinitions(): void
    {
        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponse();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-td', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-td', $prompt, $response));

        $spans = $transaction->getSpanRecorder()->getSpans();
        $data = $spans[1]->getData();

        $this->assertArrayHasKey('gen_ai.tool.definitions', $data);
        $toolDefs = json_decode($data['gen_ai.tool.definitions'], true);
        $this->assertCount(1, $toolDefs);
        $this->assertEquals('WeatherLookup', $toolDefs[0]['name']);
        $this->assertEquals('Looks up the current weather for a given location.', $toolDefs[0]['description']);
    }

    public function testAgentSpanCapturesInputMessagesWhenPiiEnabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponse();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-6', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-6', $prompt, $response));

        $spans = $transaction->getSpanRecorder()->getSpans();
        $agentData = $spans[1]->getData();

        // Check input messages use {role, parts} format
        $this->assertArrayHasKey('gen_ai.input.messages', $agentData);
        $inputMessages = json_decode($agentData['gen_ai.input.messages'], true);
        $this->assertEquals('user', $inputMessages[0]['role']);
        $this->assertEquals('text', $inputMessages[0]['parts'][0]['type']);
        $this->assertStringContainsString('Analyze this transcript', $inputMessages[0]['parts'][0]['content']);

        // System instructions
        $this->assertArrayHasKey('gen_ai.system_instructions', $agentData);
        $this->assertEquals('You are a helpful assistant.', $agentData['gen_ai.system_instructions']);

        // Output messages use {role, parts} format
        $this->assertArrayHasKey('gen_ai.output.messages', $agentData);
        $outputMessages = json_decode($agentData['gen_ai.output.messages'], true);
        $this->assertEquals('assistant', $outputMessages[0]['role']);
        $this->assertEquals('text', $outputMessages[0]['parts'][0]['type']);
        $this->assertStringContainsString('The analysis shows', $outputMessages[0]['parts'][0]['content']);
    }

    public function testAgentSpanDoesNotCaptureMessagesWhenPiiDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => false,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponse();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-7', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-7', $prompt, $response));

        $spans = $transaction->getSpanRecorder()->getSpans();
        $data = $spans[1]->getData();

        $this->assertArrayNotHasKey('gen_ai.input.messages', $data);
        $this->assertArrayNotHasKey('gen_ai.system_instructions', $data);
        $this->assertArrayNotHasKey('gen_ai.output.messages', $data);
    }

    public function testAgentSpanCapturesOutputMessagesWithToolCalls(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponseWithToolCalls();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-tc', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-tc', $prompt, $response));

        $spans = $transaction->getSpanRecorder()->getSpans();
        $agentData = $spans[1]->getData();

        $this->assertArrayHasKey('gen_ai.output.messages', $agentData);
        $outputMessages = json_decode($agentData['gen_ai.output.messages'], true);

        // Should have a single assistant message with text and tool_call parts
        $this->assertCount(1, $outputMessages);

        $this->assertEquals('assistant', $outputMessages[0]['role']);

        // First part is text, second part is tool_call
        $this->assertEquals('text', $outputMessages[0]['parts'][0]['type']);
        $this->assertEquals('tool_call', $outputMessages[0]['parts'][1]['type']);
        $this->assertEquals('WeatherLookup', $outputMessages[0]['parts'][1]['name']);
    }

    public function testSpanIsNotRecordedWhenTracingDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.ai' => false,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponse();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-8', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-8', $prompt, $response));

        $spans = $transaction->getSpanRecorder()->getSpans();

        // Only the transaction span, no agent or chat spans
        $this->assertCount(1, $spans);
    }

    public function testZeroTokenUsageIsNotRecorded(): void
    {
        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponse(
            promptTokens: 0,
            completionTokens: 0
        );

        $this->dispatchLaravelEvent(new PromptingAgent('inv-13', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-13', $prompt, $response));

        $spans = $transaction->getSpanRecorder()->getSpans();
        $data = $spans[1]->getData();

        $this->assertArrayNotHasKey('gen_ai.usage.input_tokens', $data);
        $this->assertArrayNotHasKey('gen_ai.usage.output_tokens', $data);
        $this->assertArrayNotHasKey('gen_ai.usage.total_tokens', $data);
    }

    // ---- gen_ai.chat span tests ----

    public function testChatSpanIsCreatedFromHttpEvents(): void
    {
        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponse();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-chat1', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-chat1', $prompt, $response));

        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');

        $this->assertCount(1, $chatSpans);

        /** @var Span $chatSpan */
        $chatSpan = $chatSpans[0];

        $this->assertEquals('gen_ai.chat', $chatSpan->getOp());
        // Description is updated with the actual model from step data
        $this->assertEquals('chat gpt-4o-2024-08-06', $chatSpan->getDescription());
        $this->assertEquals(SpanStatus::ok(), $chatSpan->getStatus());
        $this->assertEquals('auto.ai.laravel', $chatSpan->getOrigin());

        $data = $chatSpan->getData();
        $this->assertEquals('chat', $data['gen_ai.operation.name']);
        $this->assertEquals('gpt-4o-2024-08-06', $data['gen_ai.request.model']);
        $this->assertEquals('gpt-4o-2024-08-06', $data['gen_ai.response.model']);
        $this->assertEquals('TestAgent', $data['gen_ai.agent.name']);
    }

    public function testChatSpanTimingMatchesHttpRequest(): void
    {
        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponse();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-timing', $prompt));

        // No chat span yet — it's created by HTTP events, not PromptingAgent
        $chatSpansBefore = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(0, $chatSpansBefore);

        // HTTP request starts → chat span created
        $this->dispatchLlmRequestSending();

        $chatSpansDuring = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(1, $chatSpansDuring);
        $this->assertNull($chatSpansDuring[0]->getEndTimestamp());

        // HTTP response received → chat span finished
        $this->dispatchLlmResponseReceived();

        $chatSpansAfter = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(1, $chatSpansAfter);
        $this->assertNotNull($chatSpansAfter[0]->getEndTimestamp());

        $this->dispatchLaravelEvent(new AgentPrompted('inv-timing', $prompt, $response));
    }

    public function testChatSpanCapturesPerStepTokenUsage(): void
    {
        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponse(
            promptTokens: 100,
            completionTokens: 50
        );

        $this->dispatchLaravelEvent(new PromptingAgent('inv-chat2', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-chat2', $prompt, $response));

        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $chatData = $chatSpans[0]->getData();

        $this->assertEquals(100, $chatData['gen_ai.usage.input_tokens']);
        $this->assertEquals(50, $chatData['gen_ai.usage.output_tokens']);
        $this->assertEquals(150, $chatData['gen_ai.usage.total_tokens']);
    }

    public function testChatSpanCapturesFinishReason(): void
    {
        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponse();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-chat3', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-chat3', $prompt, $response));

        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $chatData = $chatSpans[0]->getData();

        $this->assertEquals('stop', $chatData['gen_ai.response.finish_reasons']);
    }

    public function testMultipleHttpRequestsCreateMultipleChatSpans(): void
    {
        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponseWithMultipleSteps();
        $agent = new TestAgent();
        $tool = new WeatherLookup();

        // Simulate real event flow:
        // 1. PromptingAgent
        $this->dispatchLaravelEvent(new PromptingAgent('inv-multi', $prompt));

        // 2. First LLM HTTP call
        $this->dispatchLlmHttpEvents();

        // 3. Tool execution
        $this->dispatchLaravelEvent(new InvokingTool('inv-multi', 'tool-m1', $agent, $tool, ['city' => 'Paris']));
        $this->dispatchLaravelEvent(new ToolInvoked('inv-multi', 'tool-m1', $agent, $tool, ['city' => 'Paris'], 'Sunny, 22C'));

        // 4. Second LLM HTTP call
        $this->dispatchLlmHttpEvents();

        // 5. AgentPrompted
        $this->dispatchLaravelEvent(new AgentPrompted('inv-multi', $prompt, $response));

        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');

        // Two chat spans: one for each LLM HTTP round-trip
        $this->assertCount(2, $chatSpans);

        // First step has tool_calls finish reason (from step enrichment)
        $this->assertEquals('tool_calls', $chatSpans[0]->getData()['gen_ai.response.finish_reasons']);
        // Second step has stop finish reason
        $this->assertEquals('stop', $chatSpans[1]->getData()['gen_ai.response.finish_reasons']);

        // Verify timing: first chat span ends before tool span starts
        $toolSpan = $this->findSpanByOp($transaction, 'gen_ai.execute_tool');
        $this->assertNotNull($toolSpan);
        $this->assertLessThanOrEqual(
            $toolSpan->getStartTimestamp(),
            $chatSpans[0]->getEndTimestamp()
        );
        // Second chat span starts after tool span ends
        $this->assertGreaterThanOrEqual(
            $toolSpan->getEndTimestamp(),
            $chatSpans[1]->getStartTimestamp()
        );
    }

    public function testChatSpanCapturesOutputMessagesWithToolCalls(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponseWithMultipleSteps();
        $agent = new TestAgent();
        $tool = new WeatherLookup();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-chatout', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new InvokingTool('inv-chatout', 'tool-co1', $agent, $tool, ['city' => 'Paris']));
        $this->dispatchLaravelEvent(new ToolInvoked('inv-chatout', 'tool-co1', $agent, $tool, ['city' => 'Paris'], 'Sunny, 22C'));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-chatout', $prompt, $response));

        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');

        // First chat span (tool_calls step)
        $step1Data = $chatSpans[0]->getData();
        $this->assertArrayHasKey('gen_ai.output.messages', $step1Data);
        $step1Messages = json_decode($step1Data['gen_ai.output.messages'], true);

        // Should have an assistant message with tool_call part
        $this->assertEquals('assistant', $step1Messages[0]['role']);
        $foundToolCall = false;
        foreach ($step1Messages[0]['parts'] as $part) {
            if ($part['type'] === 'tool_call') {
                $foundToolCall = true;
                $this->assertEquals('WeatherLookup', $part['name']);
            }
        }
        $this->assertTrue($foundToolCall, 'Expected to find a tool_call part in step output');

        // Should also have a tool result message
        $this->assertEquals('tool', $step1Messages[1]['role']);
        $this->assertEquals('tool_result', $step1Messages[1]['parts'][0]['type']);
        $this->assertEquals('WeatherLookup', $step1Messages[1]['parts'][0]['name']);

        // Second chat span (final text step)
        $step2Data = $chatSpans[1]->getData();
        $this->assertArrayHasKey('gen_ai.output.messages', $step2Data);
        $step2Messages = json_decode($step2Data['gen_ai.output.messages'], true);
        $this->assertEquals('assistant', $step2Messages[0]['role']);
        $this->assertEquals('text', $step2Messages[0]['parts'][0]['type']);
    }

    public function testChatSpanDoesNotCaptureOutputWhenPiiDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => false,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponse();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-chatpii', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-chatpii', $prompt, $response));

        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $chatData = $chatSpans[0]->getData();

        $this->assertArrayNotHasKey('gen_ai.output.messages', $chatData);
    }

    public function testNonMatchingHttpRequestDoesNotCreateChatSpan(): void
    {
        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponseWithNoSteps();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-nomatch', $prompt));

        // Dispatch an HTTP event to a non-LLM URL — should be ignored
        $this->dispatchHttpEvents('https://example.com/api/users');

        $this->dispatchLaravelEvent(new AgentPrompted('inv-nomatch', $prompt, $response));

        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(0, $chatSpans);
    }

    public function testHttpRequestOutsideInvocationDoesNotCreateChatSpan(): void
    {
        $transaction = $this->startTransaction();

        // Dispatch LLM HTTP events without an active invocation — should be ignored
        $this->dispatchLlmHttpEvents();

        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(0, $chatSpans);
    }

    // ---- execute_tool span tests ----

    public function testToolSpanIsRecordedAsChildOfAgent(): void
    {
        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponse();
        $agent = new TestAgent();
        $tool = new WeatherLookup();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-3', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new InvokingTool('inv-3', 'tool-1', $agent, $tool, ['query' => 'weather']));
        $this->dispatchLaravelEvent(new ToolInvoked('inv-3', 'tool-1', $agent, $tool, ['query' => 'weather'], 'sunny'));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-3', $prompt, $response));

        $toolSpan = $this->findSpanByOp($transaction, 'gen_ai.execute_tool');

        $this->assertNotNull($toolSpan);
        $this->assertEquals('execute_tool WeatherLookup', $toolSpan->getDescription());
        $this->assertEquals(SpanStatus::ok(), $toolSpan->getStatus());
        $this->assertEquals('auto.ai.laravel', $toolSpan->getOrigin());

        $data = $toolSpan->getData();
        $this->assertEquals('execute_tool', $data['gen_ai.operation.name']);
        $this->assertEquals('WeatherLookup', $data['gen_ai.tool.name']);
        $this->assertEquals('function', $data['gen_ai.tool.type']);
        $this->assertEquals('TestAgent', $data['gen_ai.agent.name']);
        $this->assertEquals('Looks up the current weather for a given location.', $data['gen_ai.tool.description']);
    }

    public function testToolSpanCapturesArgumentsAndResultWhenPiiEnabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponse();
        $agent = new TestAgent();
        $tool = new WeatherLookup();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-4', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new InvokingTool('inv-4', 'tool-2', $agent, $tool, ['query' => 'weather in Paris']));
        $this->dispatchLaravelEvent(new ToolInvoked('inv-4', 'tool-2', $agent, $tool, ['query' => 'weather in Paris'], 'Sunny, 22C'));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-4', $prompt, $response));

        $toolSpan = $this->findSpanByOp($transaction, 'gen_ai.execute_tool');

        $data = $toolSpan->getData();
        $this->assertEquals('{"query":"weather in Paris"}', $data['gen_ai.tool.call.arguments']);
        $this->assertEquals('Sunny, 22C', $data['gen_ai.tool.call.result']);
    }

    public function testToolSpanDoesNotCaptureArgumentsWhenPiiDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => false,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponse();
        $agent = new TestAgent();
        $tool = new WeatherLookup();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-5', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new InvokingTool('inv-5', 'tool-3', $agent, $tool, ['query' => 'secret data']));
        $this->dispatchLaravelEvent(new ToolInvoked('inv-5', 'tool-3', $agent, $tool, ['query' => 'secret data'], 'secret result'));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-5', $prompt, $response));

        $toolSpan = $this->findSpanByOp($transaction, 'gen_ai.execute_tool');

        $data = $toolSpan->getData();
        $this->assertArrayNotHasKey('gen_ai.tool.call.arguments', $data);
        $this->assertArrayNotHasKey('gen_ai.tool.call.result', $data);
    }

    public function testMultipleToolCallsAreTrackedCorrectly(): void
    {
        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponse();
        $agent = new TestAgent();
        $tool1 = new WeatherLookup();
        $tool2 = new DatabaseQuery();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-12', $prompt));
        $this->dispatchLlmHttpEvents();

        $this->dispatchLaravelEvent(new InvokingTool('inv-12', 'tool-a', $agent, $tool1, ['city' => 'Paris']));
        $this->dispatchLaravelEvent(new ToolInvoked('inv-12', 'tool-a', $agent, $tool1, ['city' => 'Paris'], 'sunny'));

        $this->dispatchLaravelEvent(new InvokingTool('inv-12', 'tool-b', $agent, $tool2, ['sql' => 'SELECT 1']));
        $this->dispatchLaravelEvent(new ToolInvoked('inv-12', 'tool-b', $agent, $tool2, ['sql' => 'SELECT 1'], '1'));

        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-12', $prompt, $response));

        $toolSpans = $this->findAllSpansByOp($transaction, 'gen_ai.execute_tool');

        $this->assertCount(2, $toolSpans);
        $this->assertEquals('execute_tool WeatherLookup', $toolSpans[0]->getDescription());
        $this->assertEquals('execute_tool DatabaseQuery', $toolSpans[1]->getDescription());
    }

    public function testSpanOrderIsCorrectForMultiStepFlow(): void
    {
        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponseWithMultipleSteps();
        $agent = new TestAgent();
        $tool = new WeatherLookup();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-order', $prompt));
        $this->dispatchLlmHttpEvents(); // First LLM call
        $this->dispatchLaravelEvent(new InvokingTool('inv-order', 'tool-o1', $agent, $tool, ['city' => 'Paris']));
        $this->dispatchLaravelEvent(new ToolInvoked('inv-order', 'tool-o1', $agent, $tool, ['city' => 'Paris'], 'Sunny'));
        $this->dispatchLlmHttpEvents(); // Second LLM call
        $this->dispatchLaravelEvent(new AgentPrompted('inv-order', $prompt, $response));

        $spans = $transaction->getSpanRecorder()->getSpans();

        // Transaction, invoke_agent, chat#0, execute_tool, chat#1 = 5
        $this->assertCount(5, $spans);

        // Verify span types in expected order
        $this->assertEquals('gen_ai.invoke_agent', $spans[1]->getOp());
        $this->assertEquals('gen_ai.chat', $spans[2]->getOp());          // chat #0 (first LLM call)
        $this->assertEquals('gen_ai.execute_tool', $spans[3]->getOp());   // tool execution
        $this->assertEquals('gen_ai.chat', $spans[4]->getOp());          // chat #1 (second LLM call)
    }

    // ---- Edge case tests ----

    public function testAgentPromptedWithoutMatchingPromptingAgentDoesNotFail(): void
    {
        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponse();

        $this->dispatchLaravelEvent(new AgentPrompted('inv-orphan', $prompt, $response));

        $spans = $transaction->getSpanRecorder()->getSpans();
        $this->assertCount(1, $spans); // Only transaction
    }

    public function testToolInvokedWithoutMatchingInvokingToolDoesNotFail(): void
    {
        $transaction = $this->startTransaction();

        $agent = new TestAgent();
        $tool = new WeatherLookup();

        $this->dispatchLaravelEvent(new ToolInvoked('inv-orphan', 'tool-orphan', $agent, $tool, [], 'result'));

        $spans = $transaction->getSpanRecorder()->getSpans();
        $this->assertCount(1, $spans); // Only transaction
    }

    public function testNoChatSpansWhenNoHttpEventsAndNoSteps(): void
    {
        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponseWithNoSteps();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-nosteps', $prompt));
        // No HTTP events dispatched (e.g. provider URL not configured)
        $this->dispatchLaravelEvent(new AgentPrompted('inv-nosteps', $prompt, $response));

        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');

        // No chat spans — they are only created by HTTP events
        $this->assertCount(0, $chatSpans);

        $spans = $transaction->getSpanRecorder()->getSpans();
        // Transaction + invoke_agent only
        $this->assertCount(2, $spans);
        $this->assertEquals('gen_ai.invoke_agent', $spans[1]->getOp());
    }

    /**
     * Test that chat spans work correctly when steps are provided as arrays
     * (e.g. when Collection->toArray() recursively converts Step objects to arrays).
     */
    public function testChatSpansFromArraySteps(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        $agent = new TestAgent();
        $provider = new TestProvider();

        $prompt = (object)[
            'agent' => $agent,
            'provider' => $provider,
            'model' => 'gpt-4o',
            'prompt' => 'Analyze this',
        ];

        // Steps as arrays (simulating Collection->toArray() behavior)
        $step = [
            'text' => 'Here is the analysis.',
            'toolCalls' => [],
            'toolResults' => [],
            'finishReason' => 'stop',
            'usage' => ['promptTokens' => 50, 'completionTokens' => 100, 'cacheReadInputTokens' => 0, 'cacheWriteInputTokens' => 0, 'reasoningTokens' => 0],
            'meta' => ['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
        ];

        $response = (object)[
            'text' => 'Here is the analysis.',
            'toolCalls' => [],
            'toolResults' => [],
            'steps' => [$step],
            'usage' => new TestUsage(50, 100),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
            'conversationId' => 'conv-abc-123',
        ];

        $this->dispatchLaravelEvent(new PromptingAgent('inv-array-steps', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-array-steps', $prompt, $response));

        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(1, $chatSpans);

        $chatData = $chatSpans[0]->getData();
        $this->assertEquals('gpt-4o-2024-08-06', $chatData['gen_ai.request.model']);
        $this->assertEquals(50, $chatData['gen_ai.usage.input_tokens']);
        $this->assertEquals(100, $chatData['gen_ai.usage.output_tokens']);
        $this->assertEquals('stop', $chatData['gen_ai.response.finish_reasons']);

        // Verify output messages are captured
        $this->assertArrayHasKey('gen_ai.output.messages', $chatData);
        $outputMessages = json_decode($chatData['gen_ai.output.messages'], true);
        $this->assertCount(1, $outputMessages);
        $this->assertEquals('assistant', $outputMessages[0]['role']);
        $this->assertEquals('text', $outputMessages[0]['parts'][0]['type']);
        $this->assertEquals('Here is the analysis.', $outputMessages[0]['parts'][0]['content']);
    }

    public function testConnectionFailedFinishesChatSpanWithError(): void
    {
        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponseWithNoSteps();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-fail', $prompt));

        // Start the HTTP request
        $this->dispatchLlmRequestSending();

        // Connection fails
        $httpRequest = $this->createMockHttpRequest(self::PROVIDER_URL . '/responses');
        $this->dispatchLaravelEvent(new ConnectionFailed(
            $httpRequest,
            new \Illuminate\Http\Client\ConnectionException('Connection timed out')
        ));

        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(1, $chatSpans);
        $this->assertEquals(SpanStatus::internalError(), $chatSpans[0]->getStatus());
        $this->assertNotNull($chatSpans[0]->getEndTimestamp());
    }

    // ---- Conversation ID tests ----

    public function testConversationIdIsSetOnAllSpanTypes(): void
    {
        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponseWithMultipleSteps();
        $agent = new TestAgent();
        $tool = new WeatherLookup();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-convid', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new InvokingTool('inv-convid', 'tool-c1', $agent, $tool, ['city' => 'Paris']));
        $this->dispatchLaravelEvent(new ToolInvoked('inv-convid', 'tool-c1', $agent, $tool, ['city' => 'Paris'], 'Sunny'));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-convid', $prompt, $response));

        // invoke_agent span
        $agentSpan = $this->findSpanByOp($transaction, 'gen_ai.invoke_agent');
        $this->assertNotNull($agentSpan);
        $this->assertEquals('conv-abc-123', $agentSpan->getData()['gen_ai.conversation.id']);

        // chat spans
        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(2, $chatSpans);
        foreach ($chatSpans as $chatSpan) {
            $this->assertEquals('conv-abc-123', $chatSpan->getData()['gen_ai.conversation.id']);
        }

        // execute_tool span
        $toolSpan = $this->findSpanByOp($transaction, 'gen_ai.execute_tool');
        $this->assertNotNull($toolSpan);
        $this->assertEquals('conv-abc-123', $toolSpan->getData()['gen_ai.conversation.id']);
    }

    public function testConversationIdIsNotSetWhenNull(): void
    {
        $transaction = $this->startTransaction();

        $agent = new TestAgent();
        $provider = new TestProvider();
        $tool = new WeatherLookup();

        $prompt = (object)[
            'agent' => $agent,
            'provider' => $provider,
            'model' => 'gpt-4o',
            'prompt' => 'Hello',
        ];

        $step = (object)[
            'text' => 'Hi there!',
            'toolCalls' => [],
            'toolResults' => [],
            'finishReason' => (object)['value' => 'stop'],
            'usage' => new TestUsage(10, 20),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
        ];

        // Response without conversationId
        $response = (object)[
            'text' => 'Hi there!',
            'toolCalls' => [],
            'toolResults' => [],
            'steps' => [$step],
            'usage' => new TestUsage(10, 20),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
        ];

        $this->dispatchLaravelEvent(new PromptingAgent('inv-noconv', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new InvokingTool('inv-noconv', 'tool-nc1', $agent, $tool, ['city' => 'Paris']));
        $this->dispatchLaravelEvent(new ToolInvoked('inv-noconv', 'tool-nc1', $agent, $tool, ['city' => 'Paris'], 'Sunny'));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-noconv', $prompt, $response));

        // None of the spans should have conversation ID
        $agentSpan = $this->findSpanByOp($transaction, 'gen_ai.invoke_agent');
        $this->assertArrayNotHasKey('gen_ai.conversation.id', $agentSpan->getData());

        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        foreach ($chatSpans as $chatSpan) {
            $this->assertArrayNotHasKey('gen_ai.conversation.id', $chatSpan->getData());
        }

        $toolSpan = $this->findSpanByOp($transaction, 'gen_ai.execute_tool');
        $this->assertArrayNotHasKey('gen_ai.conversation.id', $toolSpan->getData());
    }

    // ---- Helper methods ----

    /**
     * Find the first span with a given op in the transaction.
     */
    private function findSpanByOp(object $transaction, string $op): ?Span
    {
        foreach ($transaction->getSpanRecorder()->getSpans() as $span) {
            if ($span->getOp() === $op) {
                return $span;
            }
        }

        return null;
    }

    /**
     * Find all spans with a given op in the transaction.
     *
     * @return Span[]
     */
    private function findAllSpansByOp(object $transaction, string $op): array
    {
        $result = [];
        foreach ($transaction->getSpanRecorder()->getSpans() as $span) {
            if ($span->getOp() === $op) {
                $result[] = $span;
            }
        }

        return $result;
    }

    /**
     * Dispatch a pair of RequestSending + ResponseReceived events for an LLM URL.
     */
    private function dispatchLlmHttpEvents(): void
    {
        $this->dispatchLlmRequestSending();
        $this->dispatchLlmResponseReceived();
    }

    /**
     * Dispatch a RequestSending event for an LLM URL.
     */
    private function dispatchLlmRequestSending(): void
    {
        $httpRequest = $this->createMockHttpRequest(self::PROVIDER_URL . '/responses');
        $this->dispatchLaravelEvent(new RequestSending($httpRequest));
    }

    /**
     * Dispatch a ResponseReceived event for an LLM URL.
     */
    private function dispatchLlmResponseReceived(): void
    {
        $httpRequest = $this->createMockHttpRequest(self::PROVIDER_URL . '/responses');
        $httpResponse = $this->createMockHttpResponse();
        $this->dispatchLaravelEvent(new ResponseReceived($httpRequest, $httpResponse));
    }

    /**
     * Dispatch HTTP events for a non-LLM URL.
     */
    private function dispatchHttpEvents(string $url): void
    {
        $httpRequest = $this->createMockHttpRequest($url);
        $httpResponse = $this->createMockHttpResponse();
        $this->dispatchLaravelEvent(new RequestSending($httpRequest));
        $this->dispatchLaravelEvent(new ResponseReceived($httpRequest, $httpResponse));
    }

    /**
     * Create a mock HTTP request for testing.
     */
    private function createMockHttpRequest(string $url): HttpRequest
    {
        $psrRequest = new \GuzzleHttp\Psr7\Request('POST', $url, [], '{}');

        return new HttpRequest($psrRequest);
    }

    /**
     * Create a mock HTTP response for testing.
     */
    private function createMockHttpResponse(): HttpResponse
    {
        $psrResponse = new \GuzzleHttp\Psr7\Response(200, [], '{}');

        return new HttpResponse($psrResponse);
    }

    /**
     * Create a simple prompt and response with a single step (final text answer).
     *
     * @return array{0: object, 1: object} [prompt, response]
     */
    private function createPromptAndResponse(
        int $promptTokens = 60,
        int $completionTokens = 130,
        int $cacheReadInputTokens = 0,
        int $cacheWriteInputTokens = 0,
        int $reasoningTokens = 0
    ): array {
        $agent = new TestAgent();
        $provider = new TestProvider();

        $prompt = (object)[
            'agent' => $agent,
            'provider' => $provider,
            'model' => 'gpt-4o',
            'prompt' => 'Analyze this transcript',
        ];

        $step = (object)[
            'text' => 'The analysis shows positive trends.',
            'toolCalls' => [],
            'toolResults' => [],
            'finishReason' => (object)['value' => 'stop'],
            'usage' => new TestUsage($promptTokens, $completionTokens, $cacheReadInputTokens, $cacheWriteInputTokens, $reasoningTokens),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
        ];

        $response = (object)[
            'text' => 'The analysis shows positive trends.',
            'toolCalls' => [],
            'toolResults' => [],
            'steps' => [$step],
            'usage' => new TestUsage($promptTokens, $completionTokens, $cacheReadInputTokens, $cacheWriteInputTokens, $reasoningTokens),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
            'conversationId' => 'conv-abc-123',
        ];

        return [$prompt, $response];
    }

    /**
     * Create a response with tool calls in the output (for testing output message format).
     */
    private function createPromptAndResponseWithToolCalls(): array
    {
        $agent = new TestAgent();
        $provider = new TestProvider();

        $prompt = (object)[
            'agent' => $agent,
            'provider' => $provider,
            'model' => 'gpt-4o',
            'prompt' => 'What is the weather?',
        ];

        $toolCall = new TestToolCall('WeatherLookup', ['city' => 'Paris']);

        $response = (object)[
            'text' => 'The weather in Paris is sunny.',
            'toolCalls' => [$toolCall],
            'toolResults' => [],
            'steps' => [],
            'usage' => new TestUsage(60, 130),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
            'conversationId' => 'conv-abc-123',
        ];

        return [$prompt, $response];
    }

    /**
     * Create a response with 2 steps: one with tool calls, one with final text.
     */
    private function createPromptAndResponseWithMultipleSteps(): array
    {
        $agent = new TestAgent();
        $provider = new TestProvider();

        $prompt = (object)[
            'agent' => $agent,
            'provider' => $provider,
            'model' => 'gpt-4o',
            'prompt' => 'What is the weather in Paris?',
        ];

        $toolCall = new TestToolCall('WeatherLookup', ['city' => 'Paris']);
        $toolResult = new TestToolResult('WeatherLookup', 'Sunny, 22C');

        $step1 = (object)[
            'text' => '',
            'toolCalls' => [$toolCall],
            'toolResults' => [$toolResult],
            'finishReason' => (object)['value' => 'tool_calls'],
            'usage' => new TestUsage(60, 20),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
        ];

        $step2 = (object)[
            'text' => 'The weather in Paris is sunny and 22 degrees.',
            'toolCalls' => [],
            'toolResults' => [],
            'finishReason' => (object)['value' => 'stop'],
            'usage' => new TestUsage(80, 30),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
        ];

        $response = (object)[
            'text' => 'The weather in Paris is sunny and 22 degrees.',
            'toolCalls' => [$toolCall],
            'toolResults' => [$toolResult],
            'steps' => [$step1, $step2],
            'usage' => new TestUsage(140, 50),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
            'conversationId' => 'conv-abc-123',
        ];

        return [$prompt, $response];
    }

    /**
     * Create a response with no steps (edge case).
     */
    private function createPromptAndResponseWithNoSteps(): array
    {
        $agent = new TestAgentNoTools();
        $provider = new TestProvider();

        $prompt = (object)[
            'agent' => $agent,
            'provider' => $provider,
            'model' => 'gpt-4o',
            'prompt' => 'Hello',
        ];

        $response = (object)[
            'text' => 'Hello! How can I help?',
            'toolCalls' => [],
            'toolResults' => [],
            'steps' => [],
            'usage' => new TestUsage(10, 8),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
            'conversationId' => 'conv-abc-123',
        ];

        return [$prompt, $response];
    }
}
