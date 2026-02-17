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

if (!class_exists(\Laravel\Ai\Events\StreamingAgent::class)) {
    class StreamingAgent extends PromptingAgent
    {
        //
    }
}

if (!class_exists(\Laravel\Ai\Events\AgentStreamed::class)) {
    class AgentStreamed extends AgentPrompted
    {
        //
    }
}

if (!class_exists(\Laravel\Ai\Events\GeneratingEmbeddings::class)) {
    class GeneratingEmbeddings
    {
        public string $invocationId;
        public object $provider;
        public string $model;
        public object $prompt;

        public function __construct(string $invocationId, object $provider, string $model, object $prompt)
        {
            $this->invocationId = $invocationId;
            $this->provider = $provider;
            $this->model = $model;
            $this->prompt = $prompt;
        }
    }
}

if (!class_exists(\Laravel\Ai\Events\EmbeddingsGenerated::class)) {
    class EmbeddingsGenerated
    {
        public string $invocationId;
        public object $provider;
        public string $model;
        public object $prompt;
        public object $response;

        public function __construct(string $invocationId, object $provider, string $model, object $prompt, object $response)
        {
            $this->invocationId = $invocationId;
            $this->provider = $provider;
            $this->model = $model;
            $this->prompt = $prompt;
            $this->response = $response;
        }
    }
}

// Stub PHP 8 attribute classes that mirror the Laravel AI SDK attributes
namespace Laravel\Ai\Attributes;

if (!class_exists(\Laravel\Ai\Attributes\Temperature::class)) {
    #[\Attribute(\Attribute::TARGET_CLASS)]
    class Temperature
    {
        public function __construct(public float $value) {}
    }
}

if (!class_exists(\Laravel\Ai\Attributes\MaxTokens::class)) {
    #[\Attribute(\Attribute::TARGET_CLASS)]
    class MaxTokens
    {
        public function __construct(public int $value) {}
    }
}

// Stub File classes in the Laravel\Ai\Files namespace so is_a() checks work
namespace Laravel\Ai\Files;

if (!class_exists(\Laravel\Ai\Files\File::class)) {
    abstract class File
    {
        public ?string $name = null;

        public function name(): ?string
        {
            return $this->name;
        }

        public function as(?string $name): static
        {
            $this->name = $name;

            return $this;
        }
    }
}

if (!class_exists(\Laravel\Ai\Files\Image::class)) {
    abstract class Image extends File {}
}

if (!class_exists(\Laravel\Ai\Files\Document::class)) {
    abstract class Document extends File {}
}

if (!class_exists(\Laravel\Ai\Files\Audio::class)) {
    abstract class Audio extends File {}
}

// Stub agent, tool, and data classes with predictable class names
namespace Sentry\Laravel\Tests\Features\AiStubs;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;

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

#[Temperature(0.7)]
#[MaxTokens(4096)]
class TestAgentWithConfig
{
    public function instructions(): string
    {
        return 'You are a configured assistant.';
    }

    /** @return object[] */
    public function tools(): array
    {
        return [];
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

    public function schema(\Illuminate\Contracts\JsonSchema\JsonSchema $schema): array
    {
        return [
            'location' => $schema->string()->description('The city and state, e.g. San Francisco, CA')->required(),
            'unit' => $schema->string()->enum(['celsius', 'fahrenheit']),
        ];
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

    public function name(): string
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

// Stub file classes that extend the Laravel AI SDK abstract classes

class TestLocalImage extends \Laravel\Ai\Files\Image
{
    public function __construct(public string $path, public ?string $mime = null) {}

    public function mimeType(): ?string
    {
        return $this->mime;
    }

    public function name(): ?string
    {
        return $this->name ?? basename($this->path);
    }

    public function toArray(): array
    {
        return [
            'type' => 'local-image',
            'name' => $this->name(),
            'path' => $this->path,
            'mime' => $this->mime,
        ];
    }
}

class TestBase64Image extends \Laravel\Ai\Files\Image
{
    public function __construct(public string $base64, public ?string $mime = null) {}

    public function mimeType(): ?string
    {
        return $this->mime;
    }

    public function toArray(): array
    {
        return [
            'type' => 'base64-image',
            'name' => $this->name,
            'base64' => $this->base64,
            'mime' => $this->mime,
        ];
    }
}

class TestRemoteImage extends \Laravel\Ai\Files\Image
{
    public function __construct(public string $url, public ?string $mime = null) {}

    public function mimeType(): ?string
    {
        return $this->mime;
    }

    public function toArray(): array
    {
        return [
            'type' => 'remote-image',
            'name' => $this->name,
            'url' => $this->url,
            'mime' => $this->mime,
        ];
    }
}

class TestProviderImage extends \Laravel\Ai\Files\Image
{
    public function __construct(public string $id) {}

    public function toArray(): array
    {
        return [
            'type' => 'provider-image',
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}

class TestLocalDocument extends \Laravel\Ai\Files\Document
{
    public function __construct(public string $path, public ?string $mime = null) {}

    public function mimeType(): ?string
    {
        return $this->mime;
    }

    public function name(): ?string
    {
        return $this->name ?? basename($this->path);
    }

    public function toArray(): array
    {
        return [
            'type' => 'local-document',
            'name' => $this->name(),
            'path' => $this->path,
            'mime' => $this->mime,
        ];
    }
}

class TestRemoteDocument extends \Laravel\Ai\Files\Document
{
    public function __construct(public string $url, public ?string $mime = null) {}

    public function mimeType(): ?string
    {
        return $this->mime;
    }

    public function toArray(): array
    {
        return [
            'type' => 'remote-document',
            'name' => $this->name,
            'url' => $this->url,
            'mime' => $this->mime,
        ];
    }
}

// Now the actual test class
namespace Sentry\Laravel\Tests\Features;

use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Client\Response as HttpResponse;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolInvoked;
use Sentry\Laravel\Tests\Features\AiStubs\DatabaseQuery;
use Sentry\Laravel\Tests\Features\AiStubs\TestAgent;
use Sentry\Laravel\Tests\Features\AiStubs\TestAgentWithConfig;
use Sentry\Laravel\Tests\Features\AiStubs\TestAgentNoTools;
use Sentry\Laravel\Tests\Features\AiStubs\TestProvider;
use Sentry\Laravel\Tests\Features\AiStubs\TestToolCall;
use Sentry\Laravel\Tests\Features\AiStubs\TestToolResult;
use Sentry\Laravel\Tests\Features\AiStubs\TestUsage;
use Sentry\Laravel\Tests\Features\AiStubs\TestLocalImage;
use Sentry\Laravel\Tests\Features\AiStubs\TestBase64Image;
use Sentry\Laravel\Tests\Features\AiStubs\TestRemoteImage;
use Sentry\Laravel\Tests\Features\AiStubs\TestProviderImage;
use Sentry\Laravel\Tests\Features\AiStubs\TestLocalDocument;
use Sentry\Laravel\Tests\Features\AiStubs\TestRemoteDocument;
use Sentry\Laravel\Tests\Features\AiStubs\WeatherLookup;
use Sentry\Laravel\Tests\TestCase;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;

class AiIntegrationTest extends TestCase
{
    private const PROVIDER_URL = 'https://api.openai.com/v1';

    protected $defaultSetupConfig = [
        // Disable the HTTP client integration to avoid extra http.client spans
        // that would interfere with our span count assertions
        'sentry.tracing.http_client_requests' => false,
    ];

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
        $this->assertArrayNotHasKey('gen_ai.response.streaming', $data);
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

    public function testAgentSpanCapturesRequestParameters(): void
    {
        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponseWithConfiguredAgent();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-params', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-params', $prompt, $response));

        $spans = $transaction->getSpanRecorder()->getSpans();
        $data = $spans[1]->getData();

        $this->assertEquals(0.7, $data['gen_ai.request.temperature']);
        $this->assertEquals(4096, $data['gen_ai.request.max_tokens']);
    }

    public function testAgentSpanDoesNotSetRequestParametersWhenNotConfigured(): void
    {
        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponse();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-noparams', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-noparams', $prompt, $response));

        $spans = $transaction->getSpanRecorder()->getSpans();
        $data = $spans[1]->getData();

        $this->assertArrayNotHasKey('gen_ai.request.temperature', $data);
        $this->assertArrayNotHasKey('gen_ai.request.max_tokens', $data);
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
        $this->assertEquals('function', $toolDefs[0]['type']);
        $this->assertEquals('WeatherLookup', $toolDefs[0]['name']);
        $this->assertEquals('Looks up the current weather for a given location.', $toolDefs[0]['description']);

        // Parameters should include a JSON Schema object
        $this->assertArrayHasKey('parameters', $toolDefs[0]);
        $params = $toolDefs[0]['parameters'];
        $this->assertEquals('object', $params['type']);
        $this->assertArrayHasKey('properties', $params);
        $this->assertArrayHasKey('location', $params['properties']);
        $this->assertEquals('string', $params['properties']['location']['type']);
        $this->assertEquals('The city and state, e.g. San Francisco, CA', $params['properties']['location']['description']);
        $this->assertContains('location', $params['required']);
        $this->assertArrayHasKey('unit', $params['properties']);
        $this->assertEquals('string', $params['properties']['unit']['type']);
        $this->assertEquals(['celsius', 'fahrenheit'], $params['properties']['unit']['enum']);
    }

    public function testChatSpanHasToolDefinitions(): void
    {
        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponse();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-chat-td', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-chat-td', $prompt, $response));

        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(1, $chatSpans);

        $chatData = $chatSpans[0]->getData();
        $this->assertArrayHasKey('gen_ai.tool.definitions', $chatData);
        $toolDefs = json_decode($chatData['gen_ai.tool.definitions'], true);
        $this->assertCount(1, $toolDefs);
        $this->assertEquals('function', $toolDefs[0]['type']);
        $this->assertEquals('WeatherLookup', $toolDefs[0]['name']);
        $this->assertArrayHasKey('parameters', $toolDefs[0]);
    }

    public function testChatSpanOmitsToolDefinitionsWhenNoTools(): void
    {
        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponseWithNoSteps();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-no-td', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-no-td', $prompt, $response));

        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(1, $chatSpans);
        $this->assertArrayNotHasKey('gen_ai.tool.definitions', $chatSpans[0]->getData());
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
            'sentry.tracing.gen_ai' => false,
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

    // ---- Chat input messages tests ----

    public function testChatSpanCapturesInputMessagesFromUserPrompt(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponse();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-chatinput1', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-chatinput1', $prompt, $response));

        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(1, $chatSpans);

        $chatData = $chatSpans[0]->getData();
        $this->assertArrayHasKey('gen_ai.input.messages', $chatData);

        $inputMessages = json_decode($chatData['gen_ai.input.messages'], true);
        $this->assertCount(1, $inputMessages);
        $this->assertEquals('user', $inputMessages[0]['role']);
        $this->assertEquals('text', $inputMessages[0]['parts'][0]['type']);
        $this->assertEquals('Analyze this transcript', $inputMessages[0]['parts'][0]['content']);
    }

    public function testMultiStepChatSpansHaveCorrectInputMessages(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponseWithMultipleSteps();
        $agent = new TestAgent();
        $tool = new WeatherLookup();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-chatinput2', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new InvokingTool('inv-chatinput2', 'tool-ci1', $agent, $tool, ['city' => 'Paris']));
        $this->dispatchLaravelEvent(new ToolInvoked('inv-chatinput2', 'tool-ci1', $agent, $tool, ['city' => 'Paris'], 'Sunny, 22C'));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-chatinput2', $prompt, $response));

        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(2, $chatSpans);

        // First chat span: input is the user prompt
        $chat0Data = $chatSpans[0]->getData();
        $this->assertArrayHasKey('gen_ai.input.messages', $chat0Data);
        $input0 = json_decode($chat0Data['gen_ai.input.messages'], true);
        $this->assertCount(1, $input0);
        $this->assertEquals('user', $input0[0]['role']);
        $this->assertEquals('What is the weather in Paris?', $input0[0]['parts'][0]['content']);

        // Second chat span: input is the previous step's output (assistant tool call + tool result)
        $chat1Data = $chatSpans[1]->getData();
        $this->assertArrayHasKey('gen_ai.input.messages', $chat1Data);
        $input1 = json_decode($chat1Data['gen_ai.input.messages'], true);

        // Should have assistant message with tool_call and tool message with tool_result
        $this->assertEquals('assistant', $input1[0]['role']);
        $foundToolCall = false;
        foreach ($input1[0]['parts'] as $part) {
            if ($part['type'] === 'tool_call') {
                $foundToolCall = true;
                $this->assertEquals('WeatherLookup', $part['name']);
            }
        }
        $this->assertTrue($foundToolCall, 'Expected tool_call part in second chat input');

        $this->assertEquals('tool', $input1[1]['role']);
        $this->assertEquals('tool_result', $input1[1]['parts'][0]['type']);
        $this->assertEquals('WeatherLookup', $input1[1]['parts'][0]['name']);
        $this->assertEquals('Sunny, 22C', $input1[1]['parts'][0]['content']);
    }

    public function testChatSpanDoesNotCaptureInputMessagesWhenPiiDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => false,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponse();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-chatinput3', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-chatinput3', $prompt, $response));

        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(1, $chatSpans);

        $chatData = $chatSpans[0]->getData();
        $this->assertArrayNotHasKey('gen_ai.input.messages', $chatData);
    }

    // ---- Embeddings tests ----

    public function testEmbeddingsSpanIsRecorded(): void
    {
        $transaction = $this->startTransaction();

        [$provider, $prompt, $response] = $this->createEmbeddingsPromptAndResponse();

        $this->dispatchLaravelEvent(new GeneratingEmbeddings('emb-1', $provider, 'text-embedding-3-small', $prompt));
        $this->dispatchLaravelEvent(new EmbeddingsGenerated('emb-1', $provider, 'text-embedding-3-small', $prompt, $response));

        $spans = $transaction->getSpanRecorder()->getSpans();

        // Transaction + embeddings span = 2
        $this->assertCount(2, $spans);

        $embSpan = $spans[1];
        $this->assertEquals('gen_ai.embeddings', $embSpan->getOp());
        $this->assertEquals('embeddings text-embedding-3-small', $embSpan->getDescription());
        $this->assertEquals(SpanStatus::ok(), $embSpan->getStatus());
        $this->assertEquals('auto.ai.laravel', $embSpan->getOrigin());

        $data = $embSpan->getData();
        $this->assertEquals('embeddings', $data['gen_ai.operation.name']);
        $this->assertEquals('text-embedding-3-small', $data['gen_ai.request.model']);
        $this->assertEquals('openai', $data['gen_ai.system']);
    }

    public function testEmbeddingsSpanCapturesResponseData(): void
    {
        $transaction = $this->startTransaction();

        [$provider, $prompt, $response] = $this->createEmbeddingsPromptAndResponse();

        $this->dispatchLaravelEvent(new GeneratingEmbeddings('emb-2', $provider, 'text-embedding-3-small', $prompt));
        $this->dispatchLaravelEvent(new EmbeddingsGenerated('emb-2', $provider, 'text-embedding-3-small', $prompt, $response));

        $spans = $transaction->getSpanRecorder()->getSpans();
        $data = $spans[1]->getData();

        $this->assertEquals('text-embedding-3-small-2024', $data['gen_ai.response.model']);
        $this->assertEquals(25, $data['gen_ai.usage.input_tokens']);
    }

    public function testEmbeddingsSpanCapturesInputWhenPiiEnabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'sentry.tracing.http_client_requests' => false,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        [$provider, $prompt, $response] = $this->createEmbeddingsPromptAndResponse();

        $this->dispatchLaravelEvent(new GeneratingEmbeddings('emb-3', $provider, 'text-embedding-3-small', $prompt));
        $this->dispatchLaravelEvent(new EmbeddingsGenerated('emb-3', $provider, 'text-embedding-3-small', $prompt, $response));

        $spans = $transaction->getSpanRecorder()->getSpans();
        $data = $spans[1]->getData();

        $this->assertArrayHasKey('gen_ai.embeddings.input', $data);
        $inputs = json_decode($data['gen_ai.embeddings.input'], true);
        $this->assertCount(2, $inputs);
        $this->assertEquals('Napa Valley has great wine.', $inputs[0]);
        $this->assertEquals('Laravel is a PHP framework.', $inputs[1]);
    }

    public function testEmbeddingsSpanDoesNotCaptureInputWhenPiiDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => false,
            'sentry.tracing.http_client_requests' => false,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        [$provider, $prompt, $response] = $this->createEmbeddingsPromptAndResponse();

        $this->dispatchLaravelEvent(new GeneratingEmbeddings('emb-4', $provider, 'text-embedding-3-small', $prompt));
        $this->dispatchLaravelEvent(new EmbeddingsGenerated('emb-4', $provider, 'text-embedding-3-small', $prompt, $response));

        $spans = $transaction->getSpanRecorder()->getSpans();
        $data = $spans[1]->getData();

        $this->assertArrayNotHasKey('gen_ai.embeddings.input', $data);
    }

    public function testEmbeddingsSpanIsNotRecordedWhenTracingDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.gen_ai' => false,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        [$provider, $prompt, $response] = $this->createEmbeddingsPromptAndResponse();

        $this->dispatchLaravelEvent(new GeneratingEmbeddings('emb-5', $provider, 'text-embedding-3-small', $prompt));
        $this->dispatchLaravelEvent(new EmbeddingsGenerated('emb-5', $provider, 'text-embedding-3-small', $prompt, $response));

        $spans = $transaction->getSpanRecorder()->getSpans();

        // Only the transaction span
        $this->assertCount(1, $spans);
    }

    public function testEmbeddingsSpanIsFinishedEvenWithoutGeneratedEvent(): void
    {
        $transaction = $this->startTransaction();

        [$provider, $prompt, $response] = $this->createEmbeddingsPromptAndResponse();

        // Only dispatch the start event, not the end event
        $this->dispatchLaravelEvent(new GeneratingEmbeddings('emb-orphan', $provider, 'text-embedding-3-small', $prompt));

        $spans = $transaction->getSpanRecorder()->getSpans();

        // Transaction + embeddings span = 2 (span started but not finished)
        $this->assertCount(2, $spans);
        $this->assertNull($spans[1]->getEndTimestamp());
    }

    // ---- Streaming tests ----

    public function testStreamingAgentSpanIsRecorded(): void
    {
        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createStreamingPromptAndResponse();

        $this->dispatchLaravelEvent(new StreamingAgent('inv-stream1', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentStreamed('inv-stream1', $prompt, $response));

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
        $this->assertTrue($data['gen_ai.response.streaming']);
    }

    public function testStreamingChatSpanHasStreamingAttribute(): void
    {
        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createStreamingPromptAndResponse();

        $this->dispatchLaravelEvent(new StreamingAgent('inv-stream-attr', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentStreamed('inv-stream-attr', $prompt, $response));

        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(1, $chatSpans);
        $this->assertTrue($chatSpans[0]->getData()['gen_ai.response.streaming']);
    }

    public function testNonStreamingChatSpanDoesNotHaveStreamingAttribute(): void
    {
        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponse();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-nostream-attr', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-nostream-attr', $prompt, $response));

        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(1, $chatSpans);
        $this->assertArrayNotHasKey('gen_ai.response.streaming', $chatSpans[0]->getData());
    }

    public function testStreamingAgentSpanCapturesTokenUsage(): void
    {
        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createStreamingPromptAndResponse(
            promptTokens: 100,
            completionTokens: 50
        );

        $this->dispatchLaravelEvent(new StreamingAgent('inv-stream2', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentStreamed('inv-stream2', $prompt, $response));

        $spans = $transaction->getSpanRecorder()->getSpans();
        $data = $spans[1]->getData();

        $this->assertEquals(100, $data['gen_ai.usage.input_tokens']);
        $this->assertEquals(50, $data['gen_ai.usage.output_tokens']);
        $this->assertEquals(150, $data['gen_ai.usage.total_tokens']);
    }

    public function testStreamingAgentSpanCapturesConversationId(): void
    {
        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createStreamingPromptAndResponse();

        $this->dispatchLaravelEvent(new StreamingAgent('inv-stream3', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentStreamed('inv-stream3', $prompt, $response));

        $agentSpan = $this->findSpanByOp($transaction, 'gen_ai.invoke_agent');
        $this->assertNotNull($agentSpan);
        $this->assertEquals('conv-stream-123', $agentSpan->getData()['gen_ai.conversation.id']);

        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(1, $chatSpans);
        $this->assertEquals('conv-stream-123', $chatSpans[0]->getData()['gen_ai.conversation.id']);
    }

    public function testStreamingChatSpanIsCreatedFromHttpEvents(): void
    {
        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createStreamingPromptAndResponse();

        $this->dispatchLaravelEvent(new StreamingAgent('inv-stream4', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentStreamed('inv-stream4', $prompt, $response));

        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(1, $chatSpans);

        $chatSpan = $chatSpans[0];
        $this->assertEquals('gen_ai.chat', $chatSpan->getOp());
        $this->assertEquals(SpanStatus::ok(), $chatSpan->getStatus());
        $this->assertEquals('auto.ai.laravel', $chatSpan->getOrigin());

        // Chat span should be enriched from response-level data
        $chatData = $chatSpan->getData();
        $this->assertEquals('gpt-4o-2024-08-06', $chatData['gen_ai.request.model']);
        $this->assertEquals('gpt-4o-2024-08-06', $chatData['gen_ai.response.model']);
        $this->assertEquals('chat gpt-4o-2024-08-06', $chatSpan->getDescription());

        // Single chat span gets token usage from response
        $this->assertEquals(60, $chatData['gen_ai.usage.input_tokens']);
        $this->assertEquals(130, $chatData['gen_ai.usage.output_tokens']);
        $this->assertEquals(190, $chatData['gen_ai.usage.total_tokens']);
    }

    public function testStreamingChatSpanCapturesMessagesWhenPiiEnabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'sentry.tracing.http_client_requests' => false,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createStreamingPromptAndResponse();

        $this->dispatchLaravelEvent(new StreamingAgent('inv-stream-chatmsg', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentStreamed('inv-stream-chatmsg', $prompt, $response));

        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(1, $chatSpans);

        $chatData = $chatSpans[0]->getData();

        // Input messages: user prompt
        $this->assertArrayHasKey('gen_ai.input.messages', $chatData);
        $inputMessages = json_decode($chatData['gen_ai.input.messages'], true);
        $this->assertCount(1, $inputMessages);
        $this->assertEquals('user', $inputMessages[0]['role']);
        $this->assertEquals('text', $inputMessages[0]['parts'][0]['type']);
        $this->assertEquals('Analyze this transcript', $inputMessages[0]['parts'][0]['content']);

        // Output messages: response text
        $this->assertArrayHasKey('gen_ai.output.messages', $chatData);
        $outputMessages = json_decode($chatData['gen_ai.output.messages'], true);
        $this->assertCount(1, $outputMessages);
        $this->assertEquals('assistant', $outputMessages[0]['role']);
        $this->assertEquals('text', $outputMessages[0]['parts'][0]['type']);
        $this->assertStringContainsString('streamed analysis', $outputMessages[0]['parts'][0]['content']);
    }

    public function testStreamingChatSpanDoesNotCaptureMessagesWhenPiiDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => false,
            'sentry.tracing.http_client_requests' => false,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createStreamingPromptAndResponse();

        $this->dispatchLaravelEvent(new StreamingAgent('inv-stream-chatpii', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentStreamed('inv-stream-chatpii', $prompt, $response));

        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(1, $chatSpans);

        $chatData = $chatSpans[0]->getData();
        $this->assertArrayNotHasKey('gen_ai.input.messages', $chatData);
        $this->assertArrayNotHasKey('gen_ai.output.messages', $chatData);
    }

    public function testStreamingAgentSpanCapturesOutputMessagesWhenPiiEnabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createStreamingPromptAndResponse();

        $this->dispatchLaravelEvent(new StreamingAgent('inv-stream5', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentStreamed('inv-stream5', $prompt, $response));

        $spans = $transaction->getSpanRecorder()->getSpans();
        $agentData = $spans[1]->getData();

        // Check input messages
        $this->assertArrayHasKey('gen_ai.input.messages', $agentData);
        $inputMessages = json_decode($agentData['gen_ai.input.messages'], true);
        $this->assertEquals('user', $inputMessages[0]['role']);
        $this->assertEquals('text', $inputMessages[0]['parts'][0]['type']);
        $this->assertStringContainsString('Analyze this transcript', $inputMessages[0]['parts'][0]['content']);

        // System instructions
        $this->assertArrayHasKey('gen_ai.system_instructions', $agentData);

        // Output messages
        $this->assertArrayHasKey('gen_ai.output.messages', $agentData);
        $outputMessages = json_decode($agentData['gen_ai.output.messages'], true);
        $this->assertEquals('assistant', $outputMessages[0]['role']);
        $this->assertEquals('text', $outputMessages[0]['parts'][0]['type']);
        $this->assertStringContainsString('streamed analysis', $outputMessages[0]['parts'][0]['content']);
    }

    public function testStreamingAgentDoesNotCaptureMessagesWhenPiiDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => false,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createStreamingPromptAndResponse();

        $this->dispatchLaravelEvent(new StreamingAgent('inv-stream6', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentStreamed('inv-stream6', $prompt, $response));

        $spans = $transaction->getSpanRecorder()->getSpans();
        $data = $spans[1]->getData();

        $this->assertArrayNotHasKey('gen_ai.input.messages', $data);
        $this->assertArrayNotHasKey('gen_ai.system_instructions', $data);
        $this->assertArrayNotHasKey('gen_ai.output.messages', $data);
    }

    public function testStreamingMultiStepWithToolCalls(): void
    {
        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createStreamingPromptAndResponse();
        $agent = new TestAgent();
        $tool = new WeatherLookup();

        // Simulate streaming with tool calls: stream -> tool -> stream
        $this->dispatchLaravelEvent(new StreamingAgent('inv-stream7', $prompt));

        // First HTTP request (LLM decides to call a tool)
        $this->dispatchLlmHttpEvents();

        // Tool execution happens mid-stream
        $this->dispatchLaravelEvent(new InvokingTool('inv-stream7', 'tool-s1', $agent, $tool, ['city' => 'Paris']));
        $this->dispatchLaravelEvent(new ToolInvoked('inv-stream7', 'tool-s1', $agent, $tool, ['city' => 'Paris'], 'Sunny, 22C'));

        // Second HTTP request (LLM generates final response with tool result)
        $this->dispatchLlmHttpEvents();

        $this->dispatchLaravelEvent(new AgentStreamed('inv-stream7', $prompt, $response));

        // Verify span structure
        $spans = $transaction->getSpanRecorder()->getSpans();

        // Transaction, invoke_agent, chat#0, execute_tool, chat#1 = 5
        $this->assertCount(5, $spans);

        $this->assertEquals('gen_ai.invoke_agent', $spans[1]->getOp());
        $this->assertEquals('gen_ai.chat', $spans[2]->getOp());
        $this->assertEquals('gen_ai.execute_tool', $spans[3]->getOp());
        $this->assertEquals('gen_ai.chat', $spans[4]->getOp());

        // Tool span should have the tool name
        $this->assertEquals('execute_tool WeatherLookup', $spans[3]->getDescription());

        // Both chat spans should have model from response-level data
        $chat0Data = $spans[2]->getData();
        $chat1Data = $spans[4]->getData();
        $this->assertEquals('gpt-4o-2024-08-06', $chat0Data['gen_ai.request.model']);
        $this->assertEquals('gpt-4o-2024-08-06', $chat1Data['gen_ai.request.model']);

        // With multiple chat spans, per-step token usage is not available
        $this->assertArrayNotHasKey('gen_ai.usage.input_tokens', $chat0Data);
        $this->assertArrayNotHasKey('gen_ai.usage.input_tokens', $chat1Data);

        // All child spans should be finished (skip transaction at index 0)
        for ($i = 1; $i < count($spans); $i++) {
            $this->assertNotNull($spans[$i]->getEndTimestamp(), "Span at index {$i} ({$spans[$i]->getOp()}) should be finished");
        }
    }

    public function testStreamingMultiStepChatSpansHaveCorrectMessages(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'sentry.tracing.http_client_requests' => false,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createStreamingPromptAndResponse();
        $agent = new TestAgent();
        $tool = new WeatherLookup();

        $this->dispatchLaravelEvent(new StreamingAgent('inv-stream-msg', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new InvokingTool('inv-stream-msg', 'tool-sm1', $agent, $tool, ['city' => 'Paris']));
        $this->dispatchLaravelEvent(new ToolInvoked('inv-stream-msg', 'tool-sm1', $agent, $tool, ['city' => 'Paris'], 'Sunny, 22C'));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentStreamed('inv-stream-msg', $prompt, $response));

        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(2, $chatSpans);

        // First chat span: input is the user prompt, no output (intermediate step)
        $chat0Data = $chatSpans[0]->getData();
        $this->assertArrayHasKey('gen_ai.input.messages', $chat0Data);
        $input0 = json_decode($chat0Data['gen_ai.input.messages'], true);
        $this->assertEquals('user', $input0[0]['role']);
        $this->assertEquals('Analyze this transcript', $input0[0]['parts'][0]['content']);
        $this->assertArrayNotHasKey('gen_ai.output.messages', $chat0Data);

        // Second chat span: no input (can't determine from response-level data), output is the final text
        $chat1Data = $chatSpans[1]->getData();
        $this->assertArrayNotHasKey('gen_ai.input.messages', $chat1Data);
        $this->assertArrayHasKey('gen_ai.output.messages', $chat1Data);
        $output1 = json_decode($chat1Data['gen_ai.output.messages'], true);
        $this->assertEquals('assistant', $output1[0]['role']);
        $this->assertStringContainsString('streamed analysis', $output1[0]['parts'][0]['content']);
    }

    public function testStreamingAgentSpanIsNotRecordedWhenTracingDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.gen_ai' => false,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createStreamingPromptAndResponse();

        $this->dispatchLaravelEvent(new StreamingAgent('inv-stream8', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentStreamed('inv-stream8', $prompt, $response));

        $spans = $transaction->getSpanRecorder()->getSpans();

        // Only the transaction span, no agent or chat spans
        $this->assertCount(1, $spans);
    }

    // ---- Truncation and redaction tests ----

    public function testMessageTruncationKeepsOnlyLastMessageWhenOverBudget(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        $agent = new TestAgent();
        $provider = new TestProvider();

        // Create a prompt with very long text that will exceed 20KB when combined as messages
        // Uses spaces to avoid matching the base64 detection pattern
        $longText = str_repeat('Long input text. ', 1000);

        $prompt = (object)[
            'agent' => $agent,
            'provider' => $provider,
            'model' => 'gpt-4o',
            'prompt' => $longText,
        ];

        // Create response with multiple steps, each with substantial text
        $step1 = (object)[
            'text' => str_repeat('Step one output. ', 1000),
            'toolCalls' => [new TestToolCall('WeatherLookup', ['city' => 'Paris'])],
            'toolResults' => [new TestToolResult('WeatherLookup', 'Sunny')],
            'finishReason' => (object)['value' => 'tool_calls'],
            'usage' => new TestUsage(60, 20),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
        ];

        $step2 = (object)[
            'text' => str_repeat('Step two output. ', 1000),
            'toolCalls' => [],
            'toolResults' => [],
            'finishReason' => (object)['value' => 'stop'],
            'usage' => new TestUsage(80, 30),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
        ];

        $response = (object)[
            'text' => str_repeat('Step two output. ', 1000),
            'toolCalls' => [],
            'toolResults' => [],
            'steps' => [$step1, $step2],
            'usage' => new TestUsage(140, 50),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
            'conversationId' => 'conv-trunc-1',
        ];

        $this->dispatchLaravelEvent(new PromptingAgent('inv-trunc1', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-trunc1', $prompt, $response));

        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(2, $chatSpans);

        // First chat span output: should have only 1 message (the last one) since
        // the step output (15K text + tool calls + tool results) exceeds 20KB
        $chat0Data = $chatSpans[0]->getData();
        if (isset($chat0Data['gen_ai.output.messages'])) {
            $outputMessages = json_decode($chat0Data['gen_ai.output.messages'], true);
            // When messages are truncated, only the last message is kept
            // The content should be capped at 10K chars
            foreach ($outputMessages as $msg) {
                if (isset($msg['parts'])) {
                    foreach ($msg['parts'] as $part) {
                        if (isset($part['content'])) {
                            $this->assertLessThanOrEqual(10_003, mb_strlen($part['content']),
                                'Content should be capped at 10K chars (+ "..." suffix)');
                        }
                    }
                }
            }
        }

        // Verify the total serialized size is within budget
        $chat1Data = $chatSpans[1]->getData();
        if (isset($chat1Data['gen_ai.output.messages'])) {
            $this->assertLessThanOrEqual(20_014, strlen($chat1Data['gen_ai.output.messages']),
                'Serialized output messages should be within 20KB budget (+ truncation suffix)');
        }
    }

    public function testSingleMessageContentCappedAt10KChars(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        $agent = new TestAgent();
        $provider = new TestProvider();

        // Create a prompt with text that's over 10K but under 20K
        // Uses spaces to avoid matching the base64 detection pattern
        $longText = str_repeat('Hello world! This is a test. ', 500); // ~14.5K chars

        $prompt = (object)[
            'agent' => $agent,
            'provider' => $provider,
            'model' => 'gpt-4o',
            'prompt' => $longText,
        ];

        $step = (object)[
            'text' => 'Short response.',
            'toolCalls' => [],
            'toolResults' => [],
            'finishReason' => (object)['value' => 'stop'],
            'usage' => new TestUsage(100, 10),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
        ];

        $response = (object)[
            'text' => 'Short response.',
            'toolCalls' => [],
            'toolResults' => [],
            'steps' => [$step],
            'usage' => new TestUsage(100, 10),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
            'conversationId' => 'conv-trunc-2',
        ];

        $this->dispatchLaravelEvent(new PromptingAgent('inv-trunc2', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-trunc2', $prompt, $response));

        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(1, $chatSpans);

        $chatData = $chatSpans[0]->getData();
        $this->assertArrayHasKey('gen_ai.input.messages', $chatData);

        $inputMessages = json_decode($chatData['gen_ai.input.messages'], true);
        $this->assertCount(1, $inputMessages);

        // The 12K content should be capped at 10K chars + "..." suffix
        $content = $inputMessages[0]['parts'][0]['content'];
        $this->assertEquals(10_003, mb_strlen($content), 'Content should be 10K chars + "..." suffix');
        $this->assertStringEndsWith('...', $content);
    }

    public function testBinaryContentInDataUriIsRedacted(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        $agent = new TestAgent();
        $provider = new TestProvider();

        // Create a prompt with a data URI in the text (simulating a user pasting image data)
        $dataUri = 'data:image/png;base64,' . str_repeat('iVBORw0KGgoAAAANSUhEUgAA', 100);

        $prompt = (object)[
            'agent' => $agent,
            'provider' => $provider,
            'model' => 'gpt-4o',
            'prompt' => $dataUri,
        ];

        $step = (object)[
            'text' => 'I see an image.',
            'toolCalls' => [],
            'toolResults' => [],
            'finishReason' => (object)['value' => 'stop'],
            'usage' => new TestUsage(100, 10),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
        ];

        $response = (object)[
            'text' => 'I see an image.',
            'toolCalls' => [],
            'toolResults' => [],
            'steps' => [$step],
            'usage' => new TestUsage(100, 10),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
            'conversationId' => 'conv-binary-1',
        ];

        $this->dispatchLaravelEvent(new PromptingAgent('inv-binary1', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-binary1', $prompt, $response));

        // Check agent span input messages
        $agentSpan = $this->findSpanByOp($transaction, 'gen_ai.invoke_agent');
        $agentData = $agentSpan->getData();
        $this->assertArrayHasKey('gen_ai.input.messages', $agentData);

        $inputMessages = json_decode($agentData['gen_ai.input.messages'], true);
        $content = $inputMessages[0]['parts'][0]['content'];

        // The data URI should be replaced with blob substitute
        $this->assertEquals('[Blob substitute]', $content);
    }

    public function testBase64StringContentIsRedacted(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        $agent = new TestAgent();
        $provider = new TestProvider();

        // Create a prompt with pure base64 content (>100 chars)
        $base64Content = str_repeat('QUFBQUFBQUFBQUFBQUFBQUFBQUFB', 10); // Valid base64 pattern

        $prompt = (object)[
            'agent' => $agent,
            'provider' => $provider,
            'model' => 'gpt-4o',
            'prompt' => $base64Content,
        ];

        $step = (object)[
            'text' => 'I processed the binary data.',
            'toolCalls' => [],
            'toolResults' => [],
            'finishReason' => (object)['value' => 'stop'],
            'usage' => new TestUsage(100, 10),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
        ];

        $response = (object)[
            'text' => 'I processed the binary data.',
            'toolCalls' => [],
            'toolResults' => [],
            'steps' => [$step],
            'usage' => new TestUsage(100, 10),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
            'conversationId' => 'conv-binary-2',
        ];

        $this->dispatchLaravelEvent(new PromptingAgent('inv-binary2', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-binary2', $prompt, $response));

        $agentSpan = $this->findSpanByOp($transaction, 'gen_ai.invoke_agent');
        $agentData = $agentSpan->getData();
        $this->assertArrayHasKey('gen_ai.input.messages', $agentData);

        $inputMessages = json_decode($agentData['gen_ai.input.messages'], true);
        $content = $inputMessages[0]['parts'][0]['content'];

        // The base64 string should be replaced with blob substitute
        $this->assertEquals('[Blob substitute]', $content);
    }

    public function testNormalTextIsNotRedacted(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponse();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-noredact', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-noredact', $prompt, $response));

        $agentSpan = $this->findSpanByOp($transaction, 'gen_ai.invoke_agent');
        $agentData = $agentSpan->getData();
        $this->assertArrayHasKey('gen_ai.input.messages', $agentData);

        $inputMessages = json_decode($agentData['gen_ai.input.messages'], true);
        $content = $inputMessages[0]['parts'][0]['content'];

        // Normal text should be preserved as-is
        $this->assertEquals('Analyze this transcript', $content);
    }

    public function testShortBase64StringIsNotRedacted(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        $agent = new TestAgent();
        $provider = new TestProvider();

        // Short base64-like string (under 100 chars) should not be redacted
        $shortBase64 = 'SGVsbG8gV29ybGQ='; // "Hello World" in base64

        $prompt = (object)[
            'agent' => $agent,
            'provider' => $provider,
            'model' => 'gpt-4o',
            'prompt' => $shortBase64,
        ];

        $step = (object)[
            'text' => 'Got it.',
            'toolCalls' => [],
            'toolResults' => [],
            'finishReason' => (object)['value' => 'stop'],
            'usage' => new TestUsage(10, 5),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
        ];

        $response = (object)[
            'text' => 'Got it.',
            'toolCalls' => [],
            'toolResults' => [],
            'steps' => [$step],
            'usage' => new TestUsage(10, 5),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
            'conversationId' => 'conv-binary-3',
        ];

        $this->dispatchLaravelEvent(new PromptingAgent('inv-binary3', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-binary3', $prompt, $response));

        $agentSpan = $this->findSpanByOp($transaction, 'gen_ai.invoke_agent');
        $agentData = $agentSpan->getData();

        $inputMessages = json_decode($agentData['gen_ai.input.messages'], true);
        $content = $inputMessages[0]['parts'][0]['content'];

        // Short base64-like string should NOT be redacted
        $this->assertEquals($shortBase64, $content);
    }

    public function testOutputMessagesAreAlsoTruncated(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        $agent = new TestAgent();
        $provider = new TestProvider();

        // Create a response with very long output text
        // Uses spaces to avoid matching the base64 detection pattern
        $longResponse = str_repeat('This is a very long output. ', 1000);

        $prompt = (object)[
            'agent' => $agent,
            'provider' => $provider,
            'model' => 'gpt-4o',
            'prompt' => 'Summarize everything.',
        ];

        $step = (object)[
            'text' => $longResponse,
            'toolCalls' => [],
            'toolResults' => [],
            'finishReason' => (object)['value' => 'stop'],
            'usage' => new TestUsage(50, 500),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
        ];

        $response = (object)[
            'text' => $longResponse,
            'toolCalls' => [],
            'toolResults' => [],
            'steps' => [$step],
            'usage' => new TestUsage(50, 500),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
            'conversationId' => 'conv-trunc-out',
        ];

        $this->dispatchLaravelEvent(new PromptingAgent('inv-trunc-out', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-trunc-out', $prompt, $response));

        $agentSpan = $this->findSpanByOp($transaction, 'gen_ai.invoke_agent');
        $agentData = $agentSpan->getData();
        $this->assertArrayHasKey('gen_ai.output.messages', $agentData);

        // The serialized output should be within budget
        $serialized = $agentData['gen_ai.output.messages'];
        $this->assertLessThanOrEqual(20_014, strlen($serialized),
            'Serialized output messages should be within 20KB budget (+ truncation suffix)');

        // The output text content should be capped at 10K chars
        $outputMessages = json_decode($serialized, true);
        $this->assertNotNull($outputMessages, 'Output messages should be valid JSON');
        $content = $outputMessages[0]['parts'][0]['content'] ?? '';
        $this->assertLessThanOrEqual(10_003, mb_strlen($content),
            'Output content should be capped at 10K chars (+ "..." suffix)');
    }

    public function testToolResultIsStillTruncated(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponse();
        $agent = new TestAgent();
        $tool = new WeatherLookup();

        // Very large tool result (uses spaces to avoid base64 detection)
        $largeResult = str_repeat('Result data item. ', 1500);

        $this->dispatchLaravelEvent(new PromptingAgent('inv-tool-trunc', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new InvokingTool('inv-tool-trunc', 'tool-tr1', $agent, $tool, ['q' => 'test']));
        $this->dispatchLaravelEvent(new ToolInvoked('inv-tool-trunc', 'tool-tr1', $agent, $tool, ['q' => 'test'], $largeResult));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-tool-trunc', $prompt, $response));

        $toolSpan = $this->findSpanByOp($transaction, 'gen_ai.execute_tool');
        $this->assertNotNull($toolSpan);

        $toolData = $toolSpan->getData();
        $this->assertArrayHasKey('gen_ai.tool.call.result', $toolData);

        // Tool result should be truncated
        $resultStr = $toolData['gen_ai.tool.call.result'];
        $this->assertLessThanOrEqual(20_014, strlen($resultStr),
            'Tool result should be truncated to within budget');
        $this->assertStringEndsWith('...(truncated)', $resultStr);
    }

    public function testEmbeddingInputsTruncatedByBudget(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'sentry.tracing.http_client_requests' => false,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        $provider = new TestProvider();

        // Create many large embedding inputs that exceed 20KB total
        $inputs = [];
        for ($i = 0; $i < 50; $i++) {
            $inputs[] = str_repeat("Input {$i}: ", 100);
        }

        $prompt = (object)[
            'inputs' => $inputs,
            'dimensions' => 1536,
            'provider' => $provider,
            'model' => 'text-embedding-3-small',
        ];

        $response = (object)[
            'embeddings' => array_fill(0, 50, [0.1, 0.2]),
            'tokens' => 500,
            'meta' => (object)['provider' => 'openai', 'model' => 'text-embedding-3-small-2024'],
        ];

        $this->dispatchLaravelEvent(new GeneratingEmbeddings('emb-trunc1', $provider, 'text-embedding-3-small', $prompt));
        $this->dispatchLaravelEvent(new EmbeddingsGenerated('emb-trunc1', $provider, 'text-embedding-3-small', $prompt, $response));

        $embSpan = $this->findSpanByOp($transaction, 'gen_ai.embeddings');
        $this->assertNotNull($embSpan);

        $data = $embSpan->getData();
        $this->assertArrayHasKey('gen_ai.embeddings.input', $data);

        $serialized = $data['gen_ai.embeddings.input'];
        $this->assertLessThanOrEqual(20_014, strlen($serialized),
            'Serialized embedding inputs should be within 20KB budget');

        // Should have kept some but not all inputs (working backward from end)
        $keptInputs = json_decode($serialized, true);
        $this->assertNotNull($keptInputs);
        $this->assertGreaterThan(0, count($keptInputs));
        $this->assertLessThan(50, count($keptInputs), 'Some inputs should have been dropped');

        // The kept inputs should be the last ones
        $lastKeptInput = end($keptInputs);
        $this->assertStringContainsString('Input 49:', $lastKeptInput);
    }

    public function testEmbeddingInputsNotTruncatedWhenWithinBudget(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'sentry.tracing.http_client_requests' => false,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        [$provider, $prompt, $response] = $this->createEmbeddingsPromptAndResponse();

        $this->dispatchLaravelEvent(new GeneratingEmbeddings('emb-notrunc', $provider, 'text-embedding-3-small', $prompt));
        $this->dispatchLaravelEvent(new EmbeddingsGenerated('emb-notrunc', $provider, 'text-embedding-3-small', $prompt, $response));

        $embSpan = $this->findSpanByOp($transaction, 'gen_ai.embeddings');
        $data = $embSpan->getData();

        // Small inputs should be preserved completely
        $inputs = json_decode($data['gen_ai.embeddings.input'], true);
        $this->assertCount(2, $inputs);
        $this->assertEquals('Napa Valley has great wine.', $inputs[0]);
        $this->assertEquals('Laravel is a PHP framework.', $inputs[1]);
    }

    public function testBinaryContentInOutputMessagesIsRedacted(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        $agent = new TestAgent();
        $provider = new TestProvider();

        // Response that contains a data URI in the text (unlikely but should be caught)
        $dataUri = 'data:image/jpeg;base64,' . str_repeat('AAAA', 100);

        $prompt = (object)[
            'agent' => $agent,
            'provider' => $provider,
            'model' => 'gpt-4o',
            'prompt' => 'Generate an image.',
        ];

        $step = (object)[
            'text' => $dataUri,
            'toolCalls' => [],
            'toolResults' => [],
            'finishReason' => (object)['value' => 'stop'],
            'usage' => new TestUsage(50, 100),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
        ];

        $response = (object)[
            'text' => $dataUri,
            'toolCalls' => [],
            'toolResults' => [],
            'steps' => [$step],
            'usage' => new TestUsage(50, 100),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
            'conversationId' => 'conv-binary-out',
        ];

        $this->dispatchLaravelEvent(new PromptingAgent('inv-binary-out', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-binary-out', $prompt, $response));

        // Check agent span output messages
        $agentSpan = $this->findSpanByOp($transaction, 'gen_ai.invoke_agent');
        $agentData = $agentSpan->getData();
        $this->assertArrayHasKey('gen_ai.output.messages', $agentData);

        $outputMessages = json_decode($agentData['gen_ai.output.messages'], true);
        $content = $outputMessages[0]['parts'][0]['content'];

        // The data URI should be replaced with blob substitute
        $this->assertEquals('[Blob substitute]', $content);

        // Also check the chat span output
        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(1, $chatSpans);
        $chatData = $chatSpans[0]->getData();
        $this->assertArrayHasKey('gen_ai.output.messages', $chatData);

        $chatOutput = json_decode($chatData['gen_ai.output.messages'], true);
        $chatContent = $chatOutput[0]['parts'][0]['content'];
        $this->assertEquals('[Blob substitute]', $chatContent);
    }

    public function testSmallMessagesAreNotTruncated(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponse();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-small', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-small', $prompt, $response));

        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(1, $chatSpans);

        $chatData = $chatSpans[0]->getData();

        // Input messages should be preserved completely
        $inputMessages = json_decode($chatData['gen_ai.input.messages'], true);
        $this->assertCount(1, $inputMessages);
        $this->assertEquals('Analyze this transcript', $inputMessages[0]['parts'][0]['content']);

        // Output messages should be preserved completely
        $outputMessages = json_decode($chatData['gen_ai.output.messages'], true);
        $this->assertCount(1, $outputMessages);
        $this->assertEquals('The analysis shows positive trends.', $outputMessages[0]['parts'][0]['content']);
    }

    // ---- Attachment / multimodal tests ----

    public function testLocalImageAttachmentAppearsAsRedactedBlobInInputMessages(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        $agent = new TestAgent();
        $provider = new TestProvider();
        $image = new TestLocalImage('/tmp/photo.png', 'image/png');

        $prompt = (object)[
            'agent' => $agent,
            'provider' => $provider,
            'model' => 'gpt-4o',
            'prompt' => 'Describe this image in detail.',
            'attachments' => collect([$image]),
        ];

        $step = (object)[
            'text' => 'This is a photo of a sunset.',
            'toolCalls' => [],
            'toolResults' => [],
            'finishReason' => (object)['value' => 'stop'],
            'usage' => new TestUsage(200, 50),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
        ];

        $response = (object)[
            'text' => 'This is a photo of a sunset.',
            'toolCalls' => [],
            'toolResults' => [],
            'steps' => [$step],
            'usage' => new TestUsage(200, 50),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
            'conversationId' => 'conv-img-1',
        ];

        $this->dispatchLaravelEvent(new PromptingAgent('inv-img1', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-img1', $prompt, $response));

        // Check agent span input messages
        $agentSpan = $this->findSpanByOp($transaction, 'gen_ai.invoke_agent');
        $agentData = $agentSpan->getData();
        $this->assertArrayHasKey('gen_ai.input.messages', $agentData);

        $inputMessages = json_decode($agentData['gen_ai.input.messages'], true);
        $this->assertCount(1, $inputMessages);
        $this->assertEquals('user', $inputMessages[0]['role']);

        // Should have 2 parts: text + blob (redacted image)
        $parts = $inputMessages[0]['parts'];
        $this->assertCount(2, $parts);

        // First part: text prompt
        $this->assertEquals('text', $parts[0]['type']);
        $this->assertEquals('Describe this image in detail.', $parts[0]['content']);

        // Second part: redacted image
        $this->assertEquals('blob', $parts[1]['type']);
        $this->assertEquals('image', $parts[1]['modality']);
        $this->assertEquals('[Blob substitute]', $parts[1]['content']);
        $this->assertEquals('image/png', $parts[1]['mime_type']);
        $this->assertEquals('photo.png', $parts[1]['name']);
    }

    public function testBase64ImageAttachmentAppearsAsRedactedBlob(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        $agent = new TestAgent();
        $provider = new TestProvider();
        $image = (new TestBase64Image('iVBORw0KGgoAAAANSUhEUg==', 'image/png'))->as('screenshot.png');

        $prompt = (object)[
            'agent' => $agent,
            'provider' => $provider,
            'model' => 'gpt-4o',
            'prompt' => 'What is in this image?',
            'attachments' => collect([$image]),
        ];

        $step = (object)[
            'text' => 'A screenshot.',
            'toolCalls' => [],
            'toolResults' => [],
            'finishReason' => (object)['value' => 'stop'],
            'usage' => new TestUsage(100, 20),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
        ];

        $response = (object)[
            'text' => 'A screenshot.',
            'toolCalls' => [],
            'toolResults' => [],
            'steps' => [$step],
            'usage' => new TestUsage(100, 20),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
            'conversationId' => 'conv-img-2',
        ];

        $this->dispatchLaravelEvent(new PromptingAgent('inv-img2', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-img2', $prompt, $response));

        $agentSpan = $this->findSpanByOp($transaction, 'gen_ai.invoke_agent');
        $inputMessages = json_decode($agentSpan->getData()['gen_ai.input.messages'], true);
        $parts = $inputMessages[0]['parts'];

        $this->assertCount(2, $parts);
        $this->assertEquals('blob', $parts[1]['type']);
        $this->assertEquals('image', $parts[1]['modality']);
        $this->assertEquals('[Blob substitute]', $parts[1]['content']);
        $this->assertEquals('image/png', $parts[1]['mime_type']);
        $this->assertEquals('screenshot.png', $parts[1]['name']);
    }

    public function testRemoteImageAttachmentAppearsAsUri(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        $agent = new TestAgent();
        $provider = new TestProvider();
        $image = new TestRemoteImage('https://example.com/photo.jpg', 'image/jpeg');

        $prompt = (object)[
            'agent' => $agent,
            'provider' => $provider,
            'model' => 'gpt-4o',
            'prompt' => 'Describe this image.',
            'attachments' => collect([$image]),
        ];

        $step = (object)[
            'text' => 'A photo.',
            'toolCalls' => [],
            'toolResults' => [],
            'finishReason' => (object)['value' => 'stop'],
            'usage' => new TestUsage(100, 20),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
        ];

        $response = (object)[
            'text' => 'A photo.',
            'toolCalls' => [],
            'toolResults' => [],
            'steps' => [$step],
            'usage' => new TestUsage(100, 20),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
            'conversationId' => 'conv-img-3',
        ];

        $this->dispatchLaravelEvent(new PromptingAgent('inv-img3', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-img3', $prompt, $response));

        $agentSpan = $this->findSpanByOp($transaction, 'gen_ai.invoke_agent');
        $inputMessages = json_decode($agentSpan->getData()['gen_ai.input.messages'], true);
        $parts = $inputMessages[0]['parts'];

        $this->assertCount(2, $parts);
        // Remote image should be a URI, not redacted
        $this->assertEquals('uri', $parts[1]['type']);
        $this->assertEquals('image', $parts[1]['modality']);
        $this->assertEquals('https://example.com/photo.jpg', $parts[1]['content']);
        $this->assertEquals('image/jpeg', $parts[1]['mime_type']);
    }

    public function testProviderImageAttachmentAppearsAsFileId(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        $agent = new TestAgent();
        $provider = new TestProvider();
        $image = new TestProviderImage('file-abc123');

        $prompt = (object)[
            'agent' => $agent,
            'provider' => $provider,
            'model' => 'gpt-4o',
            'prompt' => 'Describe this image.',
            'attachments' => collect([$image]),
        ];

        $step = (object)[
            'text' => 'A photo.',
            'toolCalls' => [],
            'toolResults' => [],
            'finishReason' => (object)['value' => 'stop'],
            'usage' => new TestUsage(100, 20),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
        ];

        $response = (object)[
            'text' => 'A photo.',
            'toolCalls' => [],
            'toolResults' => [],
            'steps' => [$step],
            'usage' => new TestUsage(100, 20),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
            'conversationId' => 'conv-img-4',
        ];

        $this->dispatchLaravelEvent(new PromptingAgent('inv-img4', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-img4', $prompt, $response));

        $agentSpan = $this->findSpanByOp($transaction, 'gen_ai.invoke_agent');
        $inputMessages = json_decode($agentSpan->getData()['gen_ai.input.messages'], true);
        $parts = $inputMessages[0]['parts'];

        $this->assertCount(2, $parts);
        $this->assertEquals('file_id', $parts[1]['type']);
        $this->assertEquals('image', $parts[1]['modality']);
        $this->assertEquals('file-abc123', $parts[1]['content']);
    }

    public function testDocumentAttachmentAppearsWithDocumentModality(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        $agent = new TestAgent();
        $provider = new TestProvider();
        $doc = new TestLocalDocument('/tmp/report.pdf', 'application/pdf');

        $prompt = (object)[
            'agent' => $agent,
            'provider' => $provider,
            'model' => 'gpt-4o',
            'prompt' => 'Summarize this document.',
            'attachments' => collect([$doc]),
        ];

        $step = (object)[
            'text' => 'The document discusses...',
            'toolCalls' => [],
            'toolResults' => [],
            'finishReason' => (object)['value' => 'stop'],
            'usage' => new TestUsage(500, 100),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
        ];

        $response = (object)[
            'text' => 'The document discusses...',
            'toolCalls' => [],
            'toolResults' => [],
            'steps' => [$step],
            'usage' => new TestUsage(500, 100),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
            'conversationId' => 'conv-doc-1',
        ];

        $this->dispatchLaravelEvent(new PromptingAgent('inv-doc1', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-doc1', $prompt, $response));

        $agentSpan = $this->findSpanByOp($transaction, 'gen_ai.invoke_agent');
        $inputMessages = json_decode($agentSpan->getData()['gen_ai.input.messages'], true);
        $parts = $inputMessages[0]['parts'];

        $this->assertCount(2, $parts);
        $this->assertEquals('blob', $parts[1]['type']);
        $this->assertEquals('document', $parts[1]['modality']);
        $this->assertEquals('[Blob substitute]', $parts[1]['content']);
        $this->assertEquals('application/pdf', $parts[1]['mime_type']);
        $this->assertEquals('report.pdf', $parts[1]['name']);
    }

    public function testMultipleAttachmentsAppearAsMultipleParts(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        $agent = new TestAgent();
        $provider = new TestProvider();

        $image1 = new TestLocalImage('/tmp/photo1.jpg', 'image/jpeg');
        $image2 = new TestRemoteImage('https://example.com/photo2.png', 'image/png');

        $prompt = (object)[
            'agent' => $agent,
            'provider' => $provider,
            'model' => 'gpt-4o',
            'prompt' => 'Compare these two images.',
            'attachments' => collect([$image1, $image2]),
        ];

        $step = (object)[
            'text' => 'The images differ in...',
            'toolCalls' => [],
            'toolResults' => [],
            'finishReason' => (object)['value' => 'stop'],
            'usage' => new TestUsage(300, 60),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
        ];

        $response = (object)[
            'text' => 'The images differ in...',
            'toolCalls' => [],
            'toolResults' => [],
            'steps' => [$step],
            'usage' => new TestUsage(300, 60),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
            'conversationId' => 'conv-multi-img',
        ];

        $this->dispatchLaravelEvent(new PromptingAgent('inv-multi-img', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-multi-img', $prompt, $response));

        $agentSpan = $this->findSpanByOp($transaction, 'gen_ai.invoke_agent');
        $inputMessages = json_decode($agentSpan->getData()['gen_ai.input.messages'], true);
        $parts = $inputMessages[0]['parts'];

        // text + 2 images = 3 parts
        $this->assertCount(3, $parts);
        $this->assertEquals('text', $parts[0]['type']);
        $this->assertEquals('blob', $parts[1]['type']);
        $this->assertEquals('image/jpeg', $parts[1]['mime_type']);
        $this->assertEquals('uri', $parts[2]['type']);
        $this->assertEquals('https://example.com/photo2.png', $parts[2]['content']);
    }

    public function testAttachmentsAppearInChatSpanInputMessages(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        $agent = new TestAgent();
        $provider = new TestProvider();
        $image = new TestLocalImage('/tmp/photo.png', 'image/png');

        $prompt = (object)[
            'agent' => $agent,
            'provider' => $provider,
            'model' => 'gpt-4o',
            'prompt' => 'Describe this image.',
            'attachments' => collect([$image]),
        ];

        $step = (object)[
            'text' => 'A sunset over the ocean.',
            'toolCalls' => [],
            'toolResults' => [],
            'finishReason' => (object)['value' => 'stop'],
            'usage' => new TestUsage(200, 40),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
        ];

        $response = (object)[
            'text' => 'A sunset over the ocean.',
            'toolCalls' => [],
            'toolResults' => [],
            'steps' => [$step],
            'usage' => new TestUsage(200, 40),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
            'conversationId' => 'conv-chat-img',
        ];

        $this->dispatchLaravelEvent(new PromptingAgent('inv-chat-img', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-chat-img', $prompt, $response));

        // Check the chat span (not just the agent span) also has the attachment
        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(1, $chatSpans);

        $chatData = $chatSpans[0]->getData();
        $this->assertArrayHasKey('gen_ai.input.messages', $chatData);

        $inputMessages = json_decode($chatData['gen_ai.input.messages'], true);
        $parts = $inputMessages[0]['parts'];

        // text + redacted image = 2 parts
        $this->assertCount(2, $parts);
        $this->assertEquals('text', $parts[0]['type']);
        $this->assertEquals('Describe this image.', $parts[0]['content']);
        $this->assertEquals('blob', $parts[1]['type']);
        $this->assertEquals('image', $parts[1]['modality']);
        $this->assertEquals('[Blob substitute]', $parts[1]['content']);
    }

    public function testAttachmentsNotIncludedWhenPiiDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => false,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        $agent = new TestAgent();
        $provider = new TestProvider();
        $image = new TestLocalImage('/tmp/photo.png', 'image/png');

        $prompt = (object)[
            'agent' => $agent,
            'provider' => $provider,
            'model' => 'gpt-4o',
            'prompt' => 'Describe this image.',
            'attachments' => collect([$image]),
        ];

        $step = (object)[
            'text' => 'A sunset.',
            'toolCalls' => [],
            'toolResults' => [],
            'finishReason' => (object)['value' => 'stop'],
            'usage' => new TestUsage(200, 40),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
        ];

        $response = (object)[
            'text' => 'A sunset.',
            'toolCalls' => [],
            'toolResults' => [],
            'steps' => [$step],
            'usage' => new TestUsage(200, 40),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
            'conversationId' => 'conv-pii-img',
        ];

        $this->dispatchLaravelEvent(new PromptingAgent('inv-pii-img', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-pii-img', $prompt, $response));

        $agentSpan = $this->findSpanByOp($transaction, 'gen_ai.invoke_agent');
        $agentData = $agentSpan->getData();

        // No input messages at all when PII is disabled
        $this->assertArrayNotHasKey('gen_ai.input.messages', $agentData);
    }

    public function testPromptWithoutAttachmentsPropertyStillWorks(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        // Use the standard prompt without attachments property (backward compat)
        [$prompt, $response] = $this->createPromptAndResponse();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-no-attach', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-no-attach', $prompt, $response));

        $agentSpan = $this->findSpanByOp($transaction, 'gen_ai.invoke_agent');
        $agentData = $agentSpan->getData();

        $inputMessages = json_decode($agentData['gen_ai.input.messages'], true);
        $this->assertCount(1, $inputMessages);

        // Only the text part, no attachment parts
        $parts = $inputMessages[0]['parts'];
        $this->assertCount(1, $parts);
        $this->assertEquals('text', $parts[0]['type']);
    }

    public function testRemoteDocumentAttachmentAppearsAsUri(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        $agent = new TestAgent();
        $provider = new TestProvider();
        $doc = new TestRemoteDocument('https://example.com/report.pdf', 'application/pdf');

        $prompt = (object)[
            'agent' => $agent,
            'provider' => $provider,
            'model' => 'gpt-4o',
            'prompt' => 'Summarize this document.',
            'attachments' => collect([$doc]),
        ];

        $step = (object)[
            'text' => 'Summary...',
            'toolCalls' => [],
            'toolResults' => [],
            'finishReason' => (object)['value' => 'stop'],
            'usage' => new TestUsage(500, 100),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
        ];

        $response = (object)[
            'text' => 'Summary...',
            'toolCalls' => [],
            'toolResults' => [],
            'steps' => [$step],
            'usage' => new TestUsage(500, 100),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
            'conversationId' => 'conv-doc-remote',
        ];

        $this->dispatchLaravelEvent(new PromptingAgent('inv-doc-remote', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-doc-remote', $prompt, $response));

        $agentSpan = $this->findSpanByOp($transaction, 'gen_ai.invoke_agent');
        $inputMessages = json_decode($agentSpan->getData()['gen_ai.input.messages'], true);
        $parts = $inputMessages[0]['parts'];

        $this->assertCount(2, $parts);
        $this->assertEquals('uri', $parts[1]['type']);
        $this->assertEquals('document', $parts[1]['modality']);
        $this->assertEquals('https://example.com/report.pdf', $parts[1]['content']);
        $this->assertEquals('application/pdf', $parts[1]['mime_type']);
    }

    // ---- Granular span type config tests ----

    public function testInvokeAgentSpanIsNotRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.gen_ai_invoke_agent' => false,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponse();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-no-agent', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-no-agent', $prompt, $response));

        $agentSpans = $this->findAllSpansByOp($transaction, 'gen_ai.invoke_agent');
        $this->assertCount(0, $agentSpans);

        // Chat spans also won't be created because no invocation was tracked
        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(0, $chatSpans);
    }

    public function testChatSpanIsNotRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.gen_ai_chat' => false,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponse();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-no-chat', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-no-chat', $prompt, $response));

        // Agent span should still be created
        $agentSpans = $this->findAllSpansByOp($transaction, 'gen_ai.invoke_agent');
        $this->assertCount(1, $agentSpans);

        // Chat spans should not be created
        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(0, $chatSpans);
    }

    public function testToolSpanIsNotRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.gen_ai_execute_tool' => false,
            'prism.providers.openai.url' => self::PROVIDER_URL,
        ]);

        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponse();
        $agent = new TestAgent();
        $tool = new WeatherLookup();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-no-tool', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new InvokingTool('inv-no-tool', 'tool-disabled', $agent, $tool, ['city' => 'Paris']));
        $this->dispatchLaravelEvent(new ToolInvoked('inv-no-tool', 'tool-disabled', $agent, $tool, ['city' => 'Paris'], 'Sunny'));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-no-tool', $prompt, $response));

        // Agent and chat spans should still be created
        $agentSpans = $this->findAllSpansByOp($transaction, 'gen_ai.invoke_agent');
        $this->assertCount(1, $agentSpans);

        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(2, $chatSpans);

        // Tool spans should not be created
        $toolSpans = $this->findAllSpansByOp($transaction, 'gen_ai.execute_tool');
        $this->assertCount(0, $toolSpans);
    }

    public function testAllGranularSpanTypesEnabledByDefault(): void
    {
        $transaction = $this->startTransaction();

        [$prompt, $response] = $this->createPromptAndResponse();
        $agent = new TestAgent();
        $tool = new WeatherLookup();

        $this->dispatchLaravelEvent(new PromptingAgent('inv-all-enabled', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new InvokingTool('inv-all-enabled', 'tool-e1', $agent, $tool, ['city' => 'Paris']));
        $this->dispatchLaravelEvent(new ToolInvoked('inv-all-enabled', 'tool-e1', $agent, $tool, ['city' => 'Paris'], 'Sunny'));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-all-enabled', $prompt, $response));

        // All span types should be present
        $agentSpans = $this->findAllSpansByOp($transaction, 'gen_ai.invoke_agent');
        $this->assertCount(1, $agentSpans);

        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(2, $chatSpans);

        $toolSpans = $this->findAllSpansByOp($transaction, 'gen_ai.execute_tool');
        $this->assertCount(1, $toolSpans);
    }

    // ---- Helper methods ----

    /**
     * Create a prompt and response for embeddings tests.
     *
     * @return array{0: object, 1: object, 2: object} [provider, prompt, response]
     */
    private function createEmbeddingsPromptAndResponse(): array
    {
        $provider = new TestProvider();

        $prompt = (object)[
            'inputs' => ['Napa Valley has great wine.', 'Laravel is a PHP framework.'],
            'dimensions' => 1536,
            'provider' => $provider,
            'model' => 'text-embedding-3-small',
        ];

        $response = (object)[
            'embeddings' => [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]],
            'tokens' => 25,
            'meta' => (object)['provider' => 'openai', 'model' => 'text-embedding-3-small-2024'],
        ];

        return [$provider, $prompt, $response];
    }

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
     * Create a prompt and response using an agent with #[Temperature] and #[MaxTokens] attributes.
     */
    private function createPromptAndResponseWithConfiguredAgent(): array
    {
        $agent = new TestAgentWithConfig();
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
            'usage' => new TestUsage(60, 130),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
        ];

        $response = (object)[
            'text' => 'The analysis shows positive trends.',
            'toolCalls' => [],
            'toolResults' => [],
            'steps' => [$step],
            'usage' => new TestUsage(60, 130),
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
     * Create a prompt and response simulating a streaming flow.
     *
     * Streaming responses differ from non-streaming: they have no steps (steps are
     * not populated for StreamedAgentResponse), but they have text, usage, meta,
     * toolCalls, toolResults, and conversationId.
     *
     * @return array{0: object, 1: object} [prompt, response]
     */
    private function createStreamingPromptAndResponse(
        int $promptTokens = 60,
        int $completionTokens = 130
    ): array {
        $agent = new TestAgent();
        $provider = new TestProvider();

        $prompt = (object)[
            'agent' => $agent,
            'provider' => $provider,
            'model' => 'gpt-4o',
            'prompt' => 'Analyze this transcript',
        ];

        // Streaming responses have no steps but have all other data
        $response = (object)[
            'text' => 'The streamed analysis shows positive trends.',
            'toolCalls' => [],
            'toolResults' => [],
            'steps' => [],
            'usage' => new TestUsage($promptTokens, $completionTokens),
            'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'],
            'conversationId' => 'conv-stream-123',
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
