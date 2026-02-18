<?php
// Stub interfaces and classes so the test works without laravel/ai installed
namespace Laravel\Ai\Contracts;

if (!interface_exists(Agent::class)) {
    interface Agent
    {
    }
}

if (!interface_exists(Provider::class)) {
    interface Provider
    {
    }
}

namespace Laravel\Ai\Responses;

if (!class_exists(AgentResponse::class)) {
    class AgentResponse
    {
    }
}

if (!class_exists(StreamableAgentResponse::class)) {
    class StreamableAgentResponse
    {
    }
}

if (!class_exists(QueuedAgentResponse::class)) {
    class QueuedAgentResponse
    {
    }
}

namespace Laravel\Ai\Prompts;

if (!class_exists(AgentPrompt::class)) {
    class AgentPrompt
    {
        public $attachments = null;

        public function __construct(public object $agent, public object $provider, public string $model, public string $prompt)
        {
        }
    }
}

namespace Laravel\Ai\Events;

if (!class_exists(PromptingAgent::class)) {
    class PromptingAgent
    {
        public function __construct(public string $invocationId, public object $prompt)
        {
        }
    }
}
if (!class_exists(AgentPrompted::class)) {
    class AgentPrompted
    {
        public function __construct(public string $invocationId, public object $prompt, public object $response)
        {
        }
    }
}
if (!class_exists(InvokingTool::class)) {
    class InvokingTool
    {
        public function __construct(public string $invocationId, public string $toolInvocationId, public object $agent, public object $tool, public array $arguments)
        {
        }
    }
}
if (!class_exists(ToolInvoked::class)) {
    class ToolInvoked
    {
        public function __construct(public string $invocationId, public string $toolInvocationId, public object $agent, public object $tool, public array $arguments, public $result)
        {
        }
    }
}
if (!class_exists(StreamingAgent::class)) {
    class StreamingAgent extends PromptingAgent
    {
    }
}
if (!class_exists(AgentStreamed::class)) {
    class AgentStreamed extends AgentPrompted
    {
    }
}
if (!class_exists(GeneratingEmbeddings::class)) {
    class GeneratingEmbeddings
    {
        public function __construct(public string $invocationId, public object $provider, public string $model, public object $prompt)
        {
        }
    }
}
if (!class_exists(EmbeddingsGenerated::class)) {
    class EmbeddingsGenerated
    {
        public function __construct(public string $invocationId, public object $provider, public string $model, public object $prompt, public object $response)
        {
        }
    }
}

namespace Laravel\Ai\Attributes;

if (!class_exists(Temperature::class)) {
    #[\Attribute(\Attribute::TARGET_CLASS)] class Temperature
    {
        public function __construct(public float $value)
        {
        }
    }
}
if (!class_exists(MaxTokens::class)) {
    #[\Attribute(\Attribute::TARGET_CLASS)] class MaxTokens
    {
        public function __construct(public int $value)
        {
        }
    }
}

namespace Laravel\Ai\Files;

if (!class_exists(File::class)) {
    abstract class File
    {
        public ?string $name = null;
        public function name(): ?string
        {
            return $this->name;
        } public function as(?string $name): static
        {
            $this->name = $name;
            return $this;
        }
    }
}
if (!class_exists(Image::class)) {
    abstract class Image extends File
    {
    }
}
if (!class_exists(Document::class)) {
    abstract class Document extends File
    {
    }
}

namespace Sentry\Laravel\Tests\Features\AiStubs;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Provider;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;

class TestAgent implements Agent
{
    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }
    public function tools(): array
    {
        return [new WeatherLookup()];
    }
    public function prompt(string $prompt, array $attachments = [], array|string|null $provider = null, ?string $model = null): AgentResponse
    {
        return new AgentResponse();
    }
    public function stream(string $prompt, array $attachments = [], array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
    {
        return new StreamableAgentResponse();
    }
    public function queue(string $prompt, array $attachments = [], array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
    {
        return new QueuedAgentResponse();
    }
    public function respond(...$args)
    {
        return null;
    }
    public function streamRespond(...$args)
    {
        return null;
    }
    public function queueRespond(...$args)
    {
        return null;
    }
    public function broadcast(string $prompt, $channels, array $attachments = [], bool $now = false, array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
    {
        return new StreamableAgentResponse();
    }
    public function broadcastNow(string $prompt, $channels, array $attachments = [], array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
    {
        return new StreamableAgentResponse();
    }
    public function broadcastOnQueue(string $prompt, $channels, array $attachments = [], array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
    {
        return new QueuedAgentResponse();
    }
}
#[Temperature(0.7)] #[MaxTokens(4096)]
class TestAgentWithConfig implements Agent
{
    public function instructions(): string
    {
        return 'You are a configured assistant.';
    }
    public function tools(): array
    {
        return [];
    }
    public function prompt(string $prompt, array $attachments = [], array|string|null $provider = null, ?string $model = null): AgentResponse
    {
        return new AgentResponse();
    }
    public function stream(string $prompt, array $attachments = [], array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
    {
        return new StreamableAgentResponse();
    }
    public function queue(string $prompt, array $attachments = [], array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
    {
        return new QueuedAgentResponse();
    }
    public function respond(...$args)
    {
        return null;
    }
    public function streamRespond(...$args)
    {
        return null;
    }
    public function queueRespond(...$args)
    {
        return null;
    }
    public function broadcast(string $prompt, $channels, array $attachments = [], bool $now = false, array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
    {
        return new StreamableAgentResponse();
    }
    public function broadcastNow(string $prompt, $channels, array $attachments = [], array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
    {
        return new StreamableAgentResponse();
    }
    public function broadcastOnQueue(string $prompt, $channels, array $attachments = [], array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
    {
        return new QueuedAgentResponse();
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
        return ['location' => $schema->string()->description('The city and state, e.g. San Francisco, CA')->required(), 'unit' => $schema->string()->enum(['celsius', 'fahrenheit'])];
    }
}
class TestProvider implements Provider
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
    public function __construct(public int $promptTokens = 0, public int $completionTokens = 0, public int $cacheReadInputTokens = 0, public int $cacheWriteInputTokens = 0, public int $reasoningTokens = 0)
    {
    }
}
class TestToolCall
{
    public function __construct(public string $name, public array $arguments = [])
    {
    }
}
class TestToolResult
{
    public function __construct(public string $name, public $result)
    {
    }
}
class TestLocalImage extends \Laravel\Ai\Files\Image
{
    public function __construct(public string $path, public ?string $mime = null)
    {
    }
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
        return ['type' => 'local-image', 'name' => $this->name(), 'path' => $this->path, 'mime' => $this->mime];
    }
}
class TestRemoteImage extends \Laravel\Ai\Files\Image
{
    public function __construct(public string $url, public ?string $mime = null)
    {
    }
    public function mimeType(): ?string
    {
        return $this->mime;
    }
    public function toArray(): array
    {
        return ['type' => 'remote-image', 'name' => $this->name, 'url' => $this->url, 'mime' => $this->mime];
    }
}
namespace Sentry\Laravel\Tests\Features;

use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Client\Response as HttpResponse;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Sentry\Laravel\Tests\Features\AiStubs\TestAgent;
use Sentry\Laravel\Tests\Features\AiStubs\TestAgentWithConfig;
use Sentry\Laravel\Tests\Features\AiStubs\TestProvider;
use Sentry\Laravel\Tests\Features\AiStubs\TestToolCall;
use Sentry\Laravel\Tests\Features\AiStubs\TestToolResult;
use Sentry\Laravel\Tests\Features\AiStubs\TestUsage;
use Sentry\Laravel\Tests\Features\AiStubs\WeatherLookup;
use Sentry\Laravel\Tests\Features\AiStubs\TestLocalImage;
use Sentry\Laravel\Tests\Features\AiStubs\TestRemoteImage;
use Sentry\Laravel\Tests\TestCase;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;

class AiIntegrationTest extends TestCase
{
    private const PROVIDER_URL = 'https://api.openai.com/v1';
    protected $defaultSetupConfig = ['sentry.tracing.http_client_requests' => false];
    protected function setUp(): void
    {
        if (\PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('Laravel AI requires PHP 8.4+.');
        }
        if (version_compare(\Illuminate\Foundation\Application::VERSION, '12', '<')) {
            $this->markTestSkipped('Laravel AI requires Laravel 12+.');
        }
        parent::setUp();
        config(['prism.providers.openai.url' => self::PROVIDER_URL]);
    }

    public function testAgentSpanIsRecorded(): void
    {
        $spans = $this->runAgentFlow($this->makePromptAndResponse());
        $this->assertCount(3, $spans); // transaction + invoke_agent + chat
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
        $this->assertEquals('conv-abc-123', $data['gen_ai.conversation.id']);
    }

    public function testAgentSpanCapturesTokenUsageAndRequestParams(): void
    {
        // Token usage
        $spans = $this->runAgentFlow($this->makePromptAndResponse(promptTokens: 100, completionTokens: 50, cacheReadInputTokens: 20, reasoningTokens: 15));
        $data = $spans[1]->getData();
        $this->assertEquals(100, $data['gen_ai.usage.input_tokens']);
        $this->assertEquals(50, $data['gen_ai.usage.output_tokens']);
        $this->assertEquals(150, $data['gen_ai.usage.total_tokens']);
        $this->assertEquals(20, $data['gen_ai.usage.input_tokens.cached']);
        $this->assertEquals(15, $data['gen_ai.usage.output_tokens.reasoning']);
        // Request parameters from attributes
        $spans = $this->runAgentFlow($this->makePromptAndResponse(agentClass: TestAgentWithConfig::class));
        $data = $spans[1]->getData();
        $this->assertEquals(0.7, $data['gen_ai.request.temperature']);
        $this->assertEquals(4096, $data['gen_ai.request.max_tokens']);
    }

    public function testPiiControlsMessageCapture(): void
    {
        // PII enabled: messages captured
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true, 'prism.providers.openai.url' => self::PROVIDER_URL]);
        $spans = $this->runAgentFlow($this->makePromptAndResponse());
        $data = $spans[1]->getData();
        $input = json_decode($data['gen_ai.input.messages'], true);
        $this->assertEquals('user', $input[0]['role']);
        $this->assertStringContainsString('Analyze this', $input[0]['parts'][0]['content']);
        $this->assertEquals('You are a helpful assistant.', $data['gen_ai.system_instructions']);
        $output = json_decode($data['gen_ai.output.messages'], true);
        $this->assertEquals('assistant', $output[0]['role']);
        // PII disabled: messages omitted
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => false, 'prism.providers.openai.url' => self::PROVIDER_URL]);
        $spans = $this->runAgentFlow($this->makePromptAndResponse());
        $data = $spans[1]->getData();
        $this->assertArrayNotHasKey('gen_ai.input.messages', $data);
        $this->assertArrayNotHasKey('gen_ai.system_instructions', $data);
        $this->assertArrayNotHasKey('gen_ai.output.messages', $data);
    }

    public function testTracingDisabledRecordsNoSpans(): void
    {
        $this->resetApplicationWithConfig(['sentry.tracing.gen_ai' => false, 'prism.providers.openai.url' => self::PROVIDER_URL]);
        $spans = $this->runAgentFlow($this->makePromptAndResponse());
        $this->assertCount(1, $spans);
    }

    public function testChatSpanIsCreatedWithStepData(): void
    {
        $transaction = $this->startTransaction();
        [$prompt, $response] = $this->makePromptAndResponse(promptTokens: 100, completionTokens: 50);
        $this->dispatchAgentFlow('inv-c', $prompt, $response);
        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(1, $chatSpans);
        $data = $chatSpans[0]->getData();
        $this->assertEquals('chat', $data['gen_ai.operation.name']);
        $this->assertEquals('gpt-4o-2024-08-06', $data['gen_ai.request.model']);
        $this->assertEquals('stop', $data['gen_ai.response.finish_reasons']);
        $this->assertEquals(100, $data['gen_ai.usage.input_tokens']);
        $this->assertEquals('conv-abc-123', $data['gen_ai.conversation.id']);
    }

    public function testMultiStepFlowWithToolCalls(): void
    {
        $transaction = $this->startTransaction();
        [$prompt, $response] = $this->makeMultiStepPromptAndResponse();
        $agent = new TestAgent();
        $tool = new WeatherLookup();
        $this->dispatchLaravelEvent(new PromptingAgent('inv-m', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new InvokingTool('inv-m', 'tool-1', $agent, $tool, ['city' => 'Paris']));
        $this->dispatchLaravelEvent(new ToolInvoked('inv-m', 'tool-1', $agent, $tool, ['city' => 'Paris'], 'Sunny, 22C'));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-m', $prompt, $response));
        $spans = $transaction->getSpanRecorder()->getSpans();
        $this->assertCount(5, $spans); // transaction + invoke_agent + chat#0 + tool + chat#1
        $this->assertEquals('gen_ai.invoke_agent', $spans[1]->getOp());
        $this->assertEquals('gen_ai.chat', $spans[2]->getOp());
        $this->assertEquals('gen_ai.execute_tool', $spans[3]->getOp());
        $this->assertEquals('gen_ai.chat', $spans[4]->getOp());
        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertEquals('tool_calls', $chatSpans[0]->getData()['gen_ai.response.finish_reasons']);
        $this->assertEquals('stop', $chatSpans[1]->getData()['gen_ai.response.finish_reasons']);
    }

    public function testToolSpanCapturesMetadataAndPiiControl(): void
    {
        // With PII: arguments and result captured
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true, 'prism.providers.openai.url' => self::PROVIDER_URL]);
        $transaction = $this->startTransaction();
        [$prompt, $response] = $this->makePromptAndResponse();
        $agent = new TestAgent();
        $tool = new WeatherLookup();
        $this->dispatchLaravelEvent(new PromptingAgent('inv-t1', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new InvokingTool('inv-t1', 'tool-1', $agent, $tool, ['query' => 'weather in Paris']));
        $this->dispatchLaravelEvent(new ToolInvoked('inv-t1', 'tool-1', $agent, $tool, ['query' => 'weather in Paris'], 'Sunny, 22C'));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-t1', $prompt, $response));
        $toolSpan = $this->findSpanByOp($transaction, 'gen_ai.execute_tool');
        $this->assertEquals('execute_tool WeatherLookup', $toolSpan->getDescription());
        $data = $toolSpan->getData();
        $this->assertEquals('WeatherLookup', $data['gen_ai.tool.name']);
        $this->assertEquals('function', $data['gen_ai.tool.type']);
        $this->assertEquals('TestAgent', $data['gen_ai.agent.name']);
        $this->assertEquals('{"query":"weather in Paris"}', $data['gen_ai.tool.call.arguments']);
        $this->assertEquals('Sunny, 22C', $data['gen_ai.tool.call.result']);
        // Without PII: arguments and result omitted
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => false, 'prism.providers.openai.url' => self::PROVIDER_URL]);
        $transaction = $this->startTransaction();
        [$prompt, $response] = $this->makePromptAndResponse();
        $this->dispatchLaravelEvent(new PromptingAgent('inv-t2', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new InvokingTool('inv-t2', 'tool-2', $agent, $tool, ['q' => 'secret']));
        $this->dispatchLaravelEvent(new ToolInvoked('inv-t2', 'tool-2', $agent, $tool, ['q' => 'secret'], 'secret'));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-t2', $prompt, $response));
        $data = $this->findSpanByOp($transaction, 'gen_ai.execute_tool')->getData();
        $this->assertArrayNotHasKey('gen_ai.tool.call.arguments', $data);
        $this->assertArrayNotHasKey('gen_ai.tool.call.result', $data);
    }

    public function testStreamingAgentHasStreamingFlag(): void
    {
        $transaction = $this->startTransaction();
        [$prompt, $response] = $this->makeStreamingPromptAndResponse();
        $this->dispatchLaravelEvent(new StreamingAgent('inv-s', $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentStreamed('inv-s', $prompt, $response));
        $this->assertTrue($transaction->getSpanRecorder()->getSpans()[1]->getData()['gen_ai.response.streaming']);
    }

    public function testEmbeddingsSpanIsRecorded(): void
    {
        $transaction = $this->startTransaction();
        [$provider, $prompt, $response] = $this->makeEmbeddingsPromptAndResponse();
        $this->dispatchLaravelEvent(new GeneratingEmbeddings('emb-1', $provider, 'text-embedding-3-small', $prompt));
        $this->dispatchLaravelEvent(new EmbeddingsGenerated('emb-1', $provider, 'text-embedding-3-small', $prompt, $response));
        $span = $transaction->getSpanRecorder()->getSpans()[1];
        $this->assertEquals('gen_ai.embeddings', $span->getOp());
        $this->assertEquals('embeddings text-embedding-3-small', $span->getDescription());
        $data = $span->getData();
        $this->assertEquals('text-embedding-3-small', $data['gen_ai.request.model']);
        $this->assertEquals('text-embedding-3-small-2024', $data['gen_ai.response.model']);
        $this->assertEquals('openai', $data['gen_ai.system']);
        $this->assertEquals(25, $data['gen_ai.usage.input_tokens']);
    }

    public function testEmbeddingsPiiControl(): void
    {
        // With PII: inputs captured
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true, 'sentry.tracing.http_client_requests' => false, 'prism.providers.openai.url' => self::PROVIDER_URL]);
        $transaction = $this->startTransaction();
        [$provider, $prompt, $response] = $this->makeEmbeddingsPromptAndResponse();
        $this->dispatchLaravelEvent(new GeneratingEmbeddings('emb-2', $provider, 'text-embedding-3-small', $prompt));
        $this->dispatchLaravelEvent(new EmbeddingsGenerated('emb-2', $provider, 'text-embedding-3-small', $prompt, $response));
        $inputs = json_decode($transaction->getSpanRecorder()->getSpans()[1]->getData()['gen_ai.embeddings.input'], true);
        $this->assertCount(2, $inputs);
        $this->assertEquals('Napa Valley has great wine.', $inputs[0]);
        // Without PII: inputs omitted
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => false, 'sentry.tracing.http_client_requests' => false, 'prism.providers.openai.url' => self::PROVIDER_URL]);
        $transaction = $this->startTransaction();
        [$provider, $prompt, $response] = $this->makeEmbeddingsPromptAndResponse();
        $this->dispatchLaravelEvent(new GeneratingEmbeddings('emb-3', $provider, 'text-embedding-3-small', $prompt));
        $this->dispatchLaravelEvent(new EmbeddingsGenerated('emb-3', $provider, 'text-embedding-3-small', $prompt, $response));
        $this->assertArrayNotHasKey('gen_ai.embeddings.input', $transaction->getSpanRecorder()->getSpans()[1]->getData());
    }

    public function testBinaryContentIsRedacted(): void
    {
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true, 'prism.providers.openai.url' => self::PROVIDER_URL]);
        $dataUri = 'data:image/png;base64,' . str_repeat('iVBORw0KGgoAAAANSUhEUgAA', 100);
        $spans = $this->runAgentFlow($this->makePromptAndResponse(promptText: $dataUri));
        $input = json_decode($this->findSpanByOpInSpans($spans, 'gen_ai.invoke_agent')->getData()['gen_ai.input.messages'], true);
        $this->assertEquals('[Blob substitute]', $input[0]['parts'][0]['content']);
    }

    public function testAttachmentHandling(): void
    {
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true, 'prism.providers.openai.url' => self::PROVIDER_URL]);
        $transaction = $this->startTransaction();
        $agent = new TestAgent();
        $provider = new TestProvider();
        $prompt = new \Laravel\Ai\Prompts\AgentPrompt($agent, $provider, 'gpt-4o', 'Compare images.');
        $prompt->attachments = collect([new TestLocalImage('/tmp/photo.png', 'image/png'), new TestRemoteImage('https://example.com/photo.jpg', 'image/jpeg')]);
        $step = (object)['text' => 'Done.', 'toolCalls' => [], 'toolResults' => [], 'finishReason' => (object)['value' => 'stop'],
            'usage' => new TestUsage(100, 20), 'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06']];
        $response = (object)['text' => 'Done.', 'toolCalls' => [], 'toolResults' => [], 'steps' => [$step],
            'usage' => new TestUsage(100, 20), 'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'], 'conversationId' => 'conv-img'];
        $this->dispatchAgentFlow('inv-a', $prompt, $response);
        $parts = json_decode($this->findSpanByOp($transaction, 'gen_ai.invoke_agent')->getData()['gen_ai.input.messages'], true)[0]['parts'];
        $this->assertCount(3, $parts); // text + local blob + remote uri
        $this->assertEquals('text', $parts[0]['type']);
        $this->assertEquals('blob', $parts[1]['type']);
        $this->assertEquals('[Blob substitute]', $parts[1]['content']);
        $this->assertEquals('image', $parts[1]['modality']);
        $this->assertEquals('uri', $parts[2]['type']);
        $this->assertEquals('https://example.com/photo.jpg', $parts[2]['content']);
    }

    public function testGranularSpanTypeDisabling(): void
    {
        // Disable invoke_agent -> no agent or chat spans
        $this->resetApplicationWithConfig(['sentry.tracing.gen_ai_invoke_agent' => false, 'prism.providers.openai.url' => self::PROVIDER_URL]);
        $spans = $this->runAgentFlow($this->makePromptAndResponse());
        $this->assertEmpty(array_filter($spans, fn ($s) => $s->getOp() === 'gen_ai.invoke_agent'));
        $this->assertEmpty(array_filter($spans, fn ($s) => $s->getOp() === 'gen_ai.chat'));
        // Disable chat -> agent span still created, no chat
        $this->resetApplicationWithConfig(['sentry.tracing.gen_ai_chat' => false, 'prism.providers.openai.url' => self::PROVIDER_URL]);
        $transaction = $this->startTransaction();
        [$prompt, $response] = $this->makePromptAndResponse();
        $this->dispatchAgentFlow('inv-gc', $prompt, $response);
        $this->assertCount(1, $this->findAllSpansByOp($transaction, 'gen_ai.invoke_agent'));
        $this->assertCount(0, $this->findAllSpansByOp($transaction, 'gen_ai.chat'));
    }

    public function testEdgeCases(): void
    {
        // Orphaned events don't crash
        $transaction = $this->startTransaction();
        [$prompt, $response] = $this->makePromptAndResponse();
        $this->dispatchLaravelEvent(new AgentPrompted('inv-orphan', $prompt, $response));
        $this->dispatchLaravelEvent(new ToolInvoked('inv-orphan', 'tool-orphan', new TestAgent(), new WeatherLookup(), [], 'result'));
        $this->assertCount(1, $transaction->getSpanRecorder()->getSpans());
        // Connection failure finishes chat span with error
        $transaction = $this->startTransaction();
        [$prompt] = $this->makePromptAndResponse();
        $this->dispatchLaravelEvent(new PromptingAgent('inv-f', $prompt));
        $this->dispatchLlmRequestSending();
        $httpReq = new HttpRequest(new \GuzzleHttp\Psr7\Request('POST', self::PROVIDER_URL . '/responses', [], '{}'));
        $this->dispatchLaravelEvent(new ConnectionFailed($httpReq, new \Illuminate\Http\Client\ConnectionException('timeout')));
        $chatSpans = $this->findAllSpansByOp($transaction, 'gen_ai.chat');
        $this->assertCount(1, $chatSpans);
        $this->assertEquals(SpanStatus::internalError(), $chatSpans[0]->getStatus());
    }

    // ---- Helpers ----

    private function runAgentFlow(array $pr, string $id = 'inv-x'): array
    {
        $t = $this->startTransaction();
        $this->dispatchAgentFlow($id, $pr[0], $pr[1]);
        return $t->getSpanRecorder()->getSpans();
    }
    private function dispatchAgentFlow(string $id, object $prompt, object $response): void
    {
        $this->dispatchLaravelEvent(new PromptingAgent($id, $prompt));
        $this->dispatchLlmHttpEvents();
        $this->dispatchLaravelEvent(new AgentPrompted($id, $prompt, $response));
    }
    private function makePromptAndResponse(int $promptTokens = 60, int $completionTokens = 130, int $cacheReadInputTokens = 0, int $reasoningTokens = 0, string $agentClass = TestAgent::class, string $promptText = 'Analyze this transcript'): array
    {
        $agent = new $agentClass();
        $provider = new TestProvider();
        $usage = new TestUsage($promptTokens, $completionTokens, $cacheReadInputTokens, 0, $reasoningTokens);
        $meta = (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'];
        $prompt = new \Laravel\Ai\Prompts\AgentPrompt($agent, $provider, 'gpt-4o', $promptText);
        $step = (object)['text' => 'The analysis shows positive trends.', 'toolCalls' => [], 'toolResults' => [], 'finishReason' => (object)['value' => 'stop'], 'usage' => $usage, 'meta' => $meta];
        $response = (object)['text' => 'The analysis shows positive trends.', 'toolCalls' => [], 'toolResults' => [], 'steps' => [$step], 'usage' => $usage, 'meta' => $meta, 'conversationId' => 'conv-abc-123'];
        return [$prompt, $response];
    }

    private function makeMultiStepPromptAndResponse(): array
    {
        $agent = new TestAgent();
        $provider = new TestProvider();
        $meta = (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'];
        $prompt = new \Laravel\Ai\Prompts\AgentPrompt($agent, $provider, 'gpt-4o', 'What is the weather in Paris?');
        $tc = new TestToolCall('WeatherLookup', ['city' => 'Paris']);
        $tr = new TestToolResult('WeatherLookup', 'Sunny, 22C');
        $step1 = (object)['text' => '', 'toolCalls' => [$tc], 'toolResults' => [$tr], 'finishReason' => (object)['value' => 'tool_calls'], 'usage' => new TestUsage(60, 20), 'meta' => $meta];
        $step2 = (object)['text' => 'Sunny and 22 degrees.', 'toolCalls' => [], 'toolResults' => [], 'finishReason' => (object)['value' => 'stop'], 'usage' => new TestUsage(80, 30), 'meta' => $meta];
        $response = (object)['text' => 'Sunny and 22 degrees.', 'toolCalls' => [$tc], 'toolResults' => [$tr], 'steps' => [$step1, $step2], 'usage' => new TestUsage(140, 50), 'meta' => $meta, 'conversationId' => 'conv-abc-123'];
        return [$prompt, $response];
    }

    private function makeStreamingPromptAndResponse(): array
    {
        $agent = new TestAgent();
        $provider = new TestProvider();
        $prompt = new \Laravel\Ai\Prompts\AgentPrompt($agent, $provider, 'gpt-4o', 'Analyze this transcript');
        $response = (object)['text' => 'Streamed analysis.', 'toolCalls' => [], 'toolResults' => [], 'steps' => [], 'usage' => new TestUsage(60, 130), 'meta' => (object)['provider' => 'openai', 'model' => 'gpt-4o-2024-08-06'], 'conversationId' => 'conv-stream-123'];
        return [$prompt, $response];
    }

    private function makeEmbeddingsPromptAndResponse(): array
    {
        $p = new TestProvider();
        $prompt = (object)['inputs' => ['Napa Valley has great wine.', 'Laravel is a PHP framework.'], 'dimensions' => 1536, 'provider' => $p, 'model' => 'text-embedding-3-small'];
        $response = (object)['embeddings' => [[0.1, 0.2], [0.4, 0.5]], 'tokens' => 25, 'meta' => (object)['provider' => 'openai', 'model' => 'text-embedding-3-small-2024']];
        return [$p, $prompt, $response];
    }

    private function findSpanByOp(object $t, string $op): ?Span
    {
        return $this->findSpanByOpInSpans($t->getSpanRecorder()->getSpans(), $op);
    }

    private function findSpanByOpInSpans(array $spans, string $op): ?Span
    {
        foreach ($spans as $s) {
            if ($s->getOp() === $op) {
                return $s;
            }
        }
        return null;
    }

    private function findAllSpansByOp(object $t, string $op): array
    {
        return array_values(array_filter($t->getSpanRecorder()->getSpans(), fn (Span $s) => $s->getOp() === $op));
    }

    private function dispatchLlmHttpEvents(): void
    {
        $this->dispatchLlmRequestSending();
        $this->dispatchLlmResponseReceived();
    }
    private function dispatchLlmRequestSending(): void
    {
        $this->dispatchLaravelEvent(new RequestSending(new HttpRequest(new \GuzzleHttp\Psr7\Request('POST', self::PROVIDER_URL . '/responses', [], '{}'))));
    }
    private function dispatchLlmResponseReceived(): void
    {
        $r = new HttpRequest(new \GuzzleHttp\Psr7\Request('POST', self::PROVIDER_URL . '/responses', [], '{}'));
        $this->dispatchLaravelEvent(new ResponseReceived($r, new HttpResponse(new \GuzzleHttp\Psr7\Response(200, [], '{}'))));
    }
}
