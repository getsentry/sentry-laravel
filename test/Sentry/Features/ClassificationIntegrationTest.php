<?php

namespace Sentry\Laravel\Tests\Features;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiManager;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Question;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\Classifying;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Providers\TypeSafeProvider;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Tools\Request as ToolRequest;
use Sentry\Laravel\Integration;
use Sentry\Laravel\Tests\TestCase;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\Transaction;

class ClassificationIntegrationTest extends TestCase
{
    private const TYPESAFE_URL = 'https://api.typesafe.ai/v1/systemone';

    private const AGENT_INVOCATION_ID = 'agent-invocation';

    protected $defaultSetupConfig = [
        'sentry.tracing.http_client_requests' => false,
        'ai.providers.typesafe.key' => 'test-key',
    ];

    protected function setUp(): void
    {
        // Classification was added in laravel/ai 1.0
        if (!class_exists(Classifying::class)) {
            $this->markTestSkipped('The laravel/ai package with classification support is not installed.');
        }

        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), [AiServiceProvider::class]);
    }

    public function testEvaluateSpanIsRecorded(): void
    {
        $transaction = $this->startTransaction();

        $this->classifyWithTypeSafe('jev-latest');

        $span = $this->findEvaluateSpan($transaction);

        $this->assertNotNull($span);
        $this->assertSame('evaluate jev-latest', $span->getDescription());
        $this->assertSame('auto.ai.laravel', $span->getOrigin());
        $this->assertSame($transaction->getSpanId(), $span->getParentSpanId());
        $this->assertEquals(SpanStatus::ok(), $span->getStatus());
        $this->assertNotNull($span->getEndTimestamp());

        $data = $span->getData();
        $this->assertSame('evaluate', $data['gen_ai.operation.name']);
        $this->assertSame('typesafe', $data['gen_ai.provider.name']);
        $this->assertSame('jev-latest', $data['gen_ai.request.model']);
        $this->assertSame('jev-1.13.0', $data['gen_ai.response.model']);
        $this->assertSame(312, $data['gen_ai.usage.input_tokens']);
        $this->assertSame(48, $data['gen_ai.usage.output_tokens']);
        $this->assertSame(360, $data['gen_ai.usage.total_tokens']);
        $this->assertArrayNotHasKey('gen_ai.conversation.id', $data);
    }

    public function testRequestModelFallsBackToTheProviderDefault(): void
    {
        $transaction = $this->startTransaction();

        $this->classifyWithTypeSafe();

        $span = $this->findEvaluateSpan($transaction);

        $this->assertSame('evaluate jev-latest', $span->getDescription());
        $this->assertSame('jev-latest', $span->getData()['gen_ai.request.model']);
    }

    public function testParentSpanIsRestoredAfterClassification(): void
    {
        $transaction = $this->startTransaction();

        $this->classifyWithTypeSafe();

        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testEvaluateSpanIsNestedUnderTheCurrentSpan(): void
    {
        $transaction = $this->startTransaction();
        $toolSpan = $transaction->startChild(SpanContext::make()->setOp('gen_ai.execute_tool'));
        $this->getSentryHubFromContainer()->setSpan($toolSpan);

        $this->classifyWithTypeSafe();

        $this->assertSame($toolSpan->getSpanId(), $this->findEvaluateSpan($transaction)->getParentSpanId());
        $this->assertSame($toolSpan, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testHttpClientSpanIsNestedUnderEvaluateSpan(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.http_client_requests' => true,
        ]);

        $transaction = $this->startTransaction();

        $this->classifyWithTypeSafe();

        $evaluateSpan = $this->findEvaluateSpan($transaction);
        $httpSpan = $this->findSpanByOp($transaction, 'http.client');

        $this->assertNotNull($httpSpan);
        $this->assertSame('POST ' . self::TYPESAFE_URL, $httpSpan->getDescription());
        $this->assertSame($evaluateSpan->getSpanId(), $httpSpan->getParentSpanId());
    }

    public function testFakedClassificationIsRecorded(): void
    {
        Classification::fake();

        $transaction = $this->startTransaction();

        $this->classify('jev-latest');

        $span = $this->findEvaluateSpan($transaction);

        $this->assertNotNull($span);
        $this->assertSame('jev-latest', $span->getData()['gen_ai.response.model']);
        // Faked responses report no token usage
        $this->assertArrayNotHasKey('gen_ai.usage.input_tokens', $span->getData());
    }

    public function testNoSpanIsRecordedWithoutSampledParent(): void
    {
        $this->fakeTypeSafe();

        $this->classify();

        $this->assertNull($this->getSentryHubFromContainer()->getSpan());
    }

    public function testEvaluateSpanCanBeDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.gen_ai_evaluate' => false,
        ]);

        $transaction = $this->startTransaction();

        $this->classifyWithTypeSafe();

        $this->assertNull($this->findEvaluateSpan($transaction));
    }

    public function testEvaluateSpanIsDisabledWithGenAiTracing(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.gen_ai' => false,
        ]);

        $transaction = $this->startTransaction();

        $this->classifyWithTypeSafe();

        $this->assertNull($this->findEvaluateSpan($transaction));
    }

    public function testErrorResponseFinishesSpanWithHttpStatus(): void
    {
        Http::fake([self::TYPESAFE_URL => Http::response(['error' => 'invalid API key'], 401)]);

        $transaction = $this->startTransaction();

        $this->assertInstanceOf(RequestException::class, $this->classifyExpectingFailure());

        $span = $this->findEvaluateSpan($transaction);

        $this->assertEquals(SpanStatus::unauthenticated(), $span->getStatus());
        $this->assertNotNull($span->getEndTimestamp());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testServerErrorFinishesSpanAfterHttpClientSpan(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.http_client_requests' => true,
        ]);

        Http::fake([self::TYPESAFE_URL => Http::response(['error' => 'internal error'], 500)]);

        $transaction = $this->startTransaction();

        $this->assertInstanceOf(RequestException::class, $this->classifyExpectingFailure());

        $evaluateSpan = $this->findEvaluateSpan($transaction);
        $httpSpan = $this->findSpanByOp($transaction, 'http.client');

        $this->assertEquals(SpanStatus::internalError(), $evaluateSpan->getStatus());
        $this->assertNotNull($evaluateSpan->getEndTimestamp());
        $this->assertSame($evaluateSpan->getSpanId(), $httpSpan->getParentSpanId());
        $this->assertNotNull($httpSpan->getEndTimestamp());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testConnectionFailureFinishesSpan(): void
    {
        Http::fake([self::TYPESAFE_URL => Http::failedConnection()]);

        $transaction = $this->startTransaction();

        // Call the provider directly, which dispatches no `ProviderFailedOver` event for the failure
        try {
            $this->app->make(AiManager::class)
                ->classificationProvider('typesafe')
                ->classify('Stripe connect keeps failing', $this->questions());

            $this->fail('Expected the classification to fail.');
        } catch (ProviderConnectionException $exception) {
            // Expected
        }

        $span = $this->findEvaluateSpan($transaction);

        $this->assertEquals(SpanStatus::internalError(), $span->getStatus());
        $this->assertNotNull($span->getEndTimestamp());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testConnectionFailureForMisconfiguredProviderUrlFinishesSpan(): void
    {
        // Without a scheme the URL has no host, and the HTTP client fails before sending anything
        config(['ai.providers.typesafe.url' => 'api.typesafe.ai/v1']);

        $transaction = $this->startTransaction();

        try {
            $this->app->make(AiManager::class)
                ->classificationProvider('typesafe')
                ->classify('Stripe connect keeps failing', $this->questions());

            $this->fail('Expected the classification to fail.');
        } catch (ProviderConnectionException $exception) {
            // Expected
        }

        $span = $this->findEvaluateSpan($transaction);

        $this->assertEquals(SpanStatus::internalError(), $span->getStatus());
        $this->assertNotNull($span->getEndTimestamp());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testFailoverRecordsASpanPerProvider(): void
    {
        config(['ai.providers.typesafe_backup' => [
            'driver' => 'typesafe',
            'key' => 'test-key',
            'url' => 'https://backup.typesafe.test/v1',
        ]]);

        Http::fake([
            self::TYPESAFE_URL => Http::response(['error' => 'rate limited'], 429),
            'https://backup.typesafe.test/v1/systemone' => Http::response($this->typeSafeResponse()),
        ]);

        $transaction = $this->startTransaction();

        $this->classify(null, ['typesafe' => 'jev-latest', 'typesafe_backup' => 'jev-latest']);

        $spans = $this->findEvaluateSpans($transaction);

        $this->assertCount(2, $spans);
        $this->assertSame('typesafe', $spans[0]->getData()['gen_ai.provider.name']);
        $this->assertEquals(SpanStatus::resourceExhausted(), $spans[0]->getStatus());
        $this->assertNotNull($spans[0]->getEndTimestamp());
        $this->assertSame('typesafe_backup', $spans[1]->getData()['gen_ai.provider.name']);
        $this->assertEquals(SpanStatus::ok(), $spans[1]->getStatus());
        $this->assertSame($transaction->getSpanId(), $spans[0]->getParentSpanId());
        $this->assertSame($transaction->getSpanId(), $spans[1]->getParentSpanId());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testFailoverWithoutHttpResponseFinishesSpan(): void
    {
        $attempts = 0;
        Classification::fake(function () use (&$attempts) {
            if ($attempts++ === 0) {
                throw RateLimitedException::forProvider('typesafe');
            }

            return [];
        });

        $transaction = $this->startTransaction();

        $this->classify(null, ['typesafe' => 'jev-latest', 'openrouter' => 'jev-latest']);

        $spans = $this->findEvaluateSpans($transaction);

        $this->assertCount(2, $spans);
        $this->assertEquals(SpanStatus::internalError(), $spans[0]->getStatus());
        $this->assertNotNull($spans[0]->getEndTimestamp());
        $this->assertEquals(SpanStatus::ok(), $spans[1]->getStatus());
        $this->assertSame($transaction->getSpanId(), $spans[1]->getParentSpanId());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testOpenRouterErrorResponseIsMatched(): void
    {
        config(['ai.providers.openrouter.key' => 'test-key']);

        Http::fake(['https://openrouter.ai/api/alpha/decisions' => Http::response(['error' => 'invalid request'], 422)]);

        $transaction = $this->startTransaction();

        $this->assertInstanceOf(RequestException::class, $this->classifyExpectingFailure('openrouter'));

        $span = $this->findEvaluateSpan($transaction);

        $this->assertSame('openrouter', $span->getData()['gen_ai.provider.name']);
        $this->assertEquals(SpanStatus::invalidArgument(), $span->getStatus());
        $this->assertNotNull($span->getEndTimestamp());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testConfiguredProviderUrlIsMatched(): void
    {
        // OpenRouter classifications go to `/alpha/decisions` below the configured URL
        config(['ai.providers.openrouter' => [
            'driver' => 'openrouter',
            'key' => 'test-key',
            'url' => 'https://proxy.test',
        ]]);

        Http::fake(['https://proxy.test/alpha/decisions' => Http::response(['error' => 'invalid request'], 422)]);

        $transaction = $this->startTransaction();

        $this->assertInstanceOf(RequestException::class, $this->classifyExpectingFailure('openrouter'));

        $span = $this->findEvaluateSpan($transaction);

        $this->assertEquals(SpanStatus::invalidArgument(), $span->getStatus());
        $this->assertNotNull($span->getEndTimestamp());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testCustomProviderErrorResponseIsMatched(): void
    {
        // A driver we don't know the host of, so only the current span identifies its response
        $this->app->make(AiManager::class)->extend('custom-classifier', function ($app, array $config) {
            return new TypeSafeProvider($config, $app['events']);
        });
        config(['ai.providers.custom' => ['driver' => 'custom-classifier', 'key' => 'test-key']]);

        Http::fake([self::TYPESAFE_URL => Http::response(['error' => 'invalid request'], 422)]);

        $transaction = $this->startTransaction();

        $this->assertInstanceOf(RequestException::class, $this->classifyExpectingFailure('custom'));

        $span = $this->findEvaluateSpan($transaction);

        $this->assertEquals(SpanStatus::invalidArgument(), $span->getStatus());
        $this->assertNotNull($span->getEndTimestamp());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testRequestToAnotherHostIsNotClaimed(): void
    {
        Http::fake([
            'https://hooks.example.test/*' => Http::response('unavailable', 500),
            self::TYPESAFE_URL => Http::response($this->typeSafeResponse()),
        ]);

        // Runs after our listener, so its request is sent while the evaluate span is current
        $this->app['events']->listen(Classifying::class, function (): void {
            Http::post('https://hooks.example.test/classifying');
        });

        $transaction = $this->startTransaction();

        $this->classify();

        $span = $this->findEvaluateSpan($transaction);

        $this->assertEquals(SpanStatus::ok(), $span->getStatus());
        $this->assertSame('jev-1.13.0', $span->getData()['gen_ai.response.model']);
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testErrorResponseIsOnlyClaimedWhileTheEvaluateSpanIsCurrent(): void
    {
        Http::fake([self::TYPESAFE_URL => Http::response(['error' => 'internal error'], 500)]);

        $transaction = $this->startTransaction();

        $provider = $this->app->make(AiManager::class)->classificationProvider('typesafe');
        $prompt = new ClassificationPrompt('Stripe connect keeps failing', $this->questions(), $provider, 'jev-latest');
        $this->dispatchLaravelEvent(new Classifying('invocation', $provider, 'jev-latest', $prompt));

        // Another span takes over while the classification is still open
        $this->getSentryHubFromContainer()->setSpan($transaction);

        Http::post(self::TYPESAFE_URL);

        $this->assertNull($this->findEvaluateSpan($transaction)->getEndTimestamp());
    }

    public function testClassificationInsideAnAgentIsNotRecordedAsChat(): void
    {
        // The agent's chat requests and the classification share the proxy host
        config(['ai.providers.openrouter' => [
            'driver' => 'openrouter',
            'key' => 'test-key',
            'url' => 'https://proxy.test',
        ]]);

        Http::fake(['https://proxy.test/alpha/decisions' => Http::response($this->typeSafeResponse())]);

        $transaction = $this->startTransaction();

        $agent = new class implements Agent {
            use Promptable;

            public function instructions(): string
            {
                return '';
            }
        };
        $provider = $this->app->make(AiManager::class)->textProvider('openrouter');
        $this->dispatchLaravelEvent(new PromptingAgent('agent-invocation', new AgentPrompt($agent, 'Triage this ticket', [], $provider, 'gpt-4o')));

        $agentSpan = $this->findSpanByOp($transaction, 'gen_ai.invoke_agent');
        $toolSpan = $agentSpan->startChild(SpanContext::make()->setOp('gen_ai.execute_tool'));
        $this->getSentryHubFromContainer()->setSpan($toolSpan);

        $this->classify(null, 'openrouter');

        $this->assertNull($this->findSpanByOp($transaction, 'gen_ai.chat'));
        $this->assertSame($toolSpan->getSpanId(), $this->findEvaluateSpan($transaction)->getParentSpanId());
        $this->assertSame($toolSpan, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testEvaluateSpanInsideAnAgentGetsTheNewConversationId(): void
    {
        $transaction = $this->startTransaction();

        $prompt = $this->classifyInsideAgentTool($this->conversationalAgent()->forUser(new \stdClass()));

        $this->dispatchLaravelEvent(new AgentPrompted(self::AGENT_INVOCATION_ID, $prompt, $this->agentResponse('conv-new')));

        $this->assertSame('conv-new', $this->findEvaluateSpan($transaction)->getData()['gen_ai.conversation.id']);
    }

    public function testEvaluateSpanInsideAFailedAgentGetsTheConversationId(): void
    {
        $transaction = $this->startTransaction();

        $user = new \stdClass();
        $agent = $this->conversationalAgent()->forUser($user);

        $prompt = $this->classifyInsideAgentTool($agent);

        $agent->continue('conv-failed', $user);
        $this->dispatchLaravelEvent(new AgentFailed(self::AGENT_INVOCATION_ID, $prompt, new \RuntimeException('The provider failed.')));

        $this->assertSame('conv-failed', $this->findEvaluateSpan($transaction)->getData()['gen_ai.conversation.id']);
    }

    public function testEvaluateSpanAfterAnAgentGetsTheLatestConversationId(): void
    {
        $transaction = $this->startTransaction();

        $this->promptAgent($this->conversationalAgent()->continue('conv-first', new \stdClass()));
        $this->classifyWithTypeSafe();

        $this->promptAgent($this->conversationalAgent()->continue('conv-second', new \stdClass()));
        $this->classifyWithTypeSafe();

        $evaluateSpans = $this->findEvaluateSpans($transaction);

        $this->assertSame('conv-first', $evaluateSpans[0]->getData()['gen_ai.conversation.id']);
        $this->assertSame('conv-second', $evaluateSpans[1]->getData()['gen_ai.conversation.id']);
    }

    public function testEvaluateSpanGetsAManuallySetConversationId(): void
    {
        $transaction = $this->startTransaction();

        Integration::setConversationId('conv-manual');
        $this->classifyWithTypeSafe();

        Integration::setConversationId(null);
        $this->classifyWithTypeSafe();

        $evaluateSpans = $this->findEvaluateSpans($transaction);

        $this->assertSame('conv-manual', $evaluateSpans[0]->getData()['gen_ai.conversation.id']);
        $this->assertArrayNotHasKey('gen_ai.conversation.id', $evaluateSpans[1]->getData());
    }

    public function testEvaluateSpanInAnotherTraceHasNoConversationId(): void
    {
        $this->startTransaction();
        $this->promptAgent($this->conversationalAgent()->continue('conv-first', new \stdClass()));

        $transaction = $this->startTransaction();
        $this->classifyWithTypeSafe();

        $this->assertArrayNotHasKey('gen_ai.conversation.id', $this->findEvaluateSpan($transaction)->getData());
    }

    public function testMessagesAreRecordedInTheTypeSafeFormat(): void
    {
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true]);

        $transaction = $this->startTransaction();

        $this->classifyWithTypeSafe();

        $data = $this->findEvaluateSpan($transaction)->getData();

        $this->assertSame([[
            'type' => 'evaluation',
            'state' => 'Stripe connect keeps failing, losing sales, help ASAP',
            'questions' => [
                'is_urgent' => ['type' => 'noul', 'instructions' => 'Does this message convey urgency?'],
                'department' => [
                    'type' => 'choice',
                    'instructions' => 'Which team should handle this?',
                    'criteria' => ['billing' => 'Payments, invoicing, refunds', 'technical' => 'Bugs, outages, integrations'],
                ],
                'frustration' => [
                    'type' => 'score',
                    'instructions' => 'How frustrated is the customer?',
                    'criteria' => ['Calm', 'Frustrated but civil', 'Very angry'],
                ],
            ],
        ]], json_decode($data['gen_ai.input.messages'], true));

        $this->assertEquals([[
            'type' => 'evaluation',
            'answers' => [
                'is_urgent' => ['type' => 'noul', 'noul' => 0.92],
                'department' => [
                    'type' => 'choice',
                    'choice' => 'technical',
                    'probabilities' => ['billing' => 0.15, 'technical' => 0.85],
                    'confidence' => 0.82,
                ],
                'frustration' => [
                    'type' => 'score',
                    'score' => 1.6,
                    'probabilities' => [0 => 0.05, 1 => 0.3, 2 => 0.65],
                    'legend' => [0 => 'Calm', 1 => 'Frustrated but civil', 2 => 'Very angry'],
                    'confidence' => 0.78,
                ],
            ],
        ]], json_decode($data['gen_ai.output.messages'], true));

        // Score levels are keyed by number but must still be JSON objects, like in the JavaScript and Python SDKs
        $this->assertStringContainsString('"probabilities":{"0":0.05,"1":0.3,"2":0.65}', $data['gen_ai.output.messages']);
        $this->assertStringContainsString('"legend":{"0":"Calm","1":"Frustrated but civil","2":"Very angry"}', $data['gen_ai.output.messages']);
    }

    public function testZeroValuesAreKeptAndMissingConfidenceIsLeftOut(): void
    {
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true]);

        Http::fake([self::TYPESAFE_URL => Http::response([
            'model' => 'jev-1.13.0',
            'answers' => [
                'is_urgent' => ['type' => 'noul', 'noul' => 0.0],
                // No confidence in the response
                'department' => ['type' => 'choice', 'choice' => 'billing', 'probabilities' => ['billing' => 1.0, 'technical' => 0.0]],
                'frustration' => [
                    'type' => 'score',
                    'score' => 0.0,
                    'legend' => ['0' => 'Calm', '1' => 'Frustrated but civil', '2' => 'Very angry'],
                    'probabilities' => ['0' => 1.0, '1' => 0.0, '2' => 0.0],
                    'confidence' => 0.0,
                ],
            ],
            'usage' => ['input_tokens' => 312, 'output_tokens' => 48],
        ])]);

        $transaction = $this->startTransaction();

        $this->classify();

        $answers = json_decode($this->findEvaluateSpan($transaction)->getData()['gen_ai.output.messages'], true)[0]['answers'];

        $this->assertEquals(0, $answers['is_urgent']['noul']);
        $this->assertArrayNotHasKey('confidence', $answers['department']);
        $this->assertEquals(['billing' => 1, 'technical' => 0], $answers['department']['probabilities']);
        $this->assertEquals(0, $answers['frustration']['score']);
        $this->assertArrayHasKey('confidence', $answers['frustration']);
        $this->assertEquals(0, $answers['frustration']['confidence']);
        $this->assertEquals([0 => 1, 1 => 0, 2 => 0], $answers['frustration']['probabilities']);
    }

    public function testFailedClassificationOnlyRecordsInputMessages(): void
    {
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true]);

        Http::fake([self::TYPESAFE_URL => Http::response(['error' => 'invalid API key'], 401)]);

        $transaction = $this->startTransaction();

        $this->classifyExpectingFailure();

        $data = $this->findEvaluateSpan($transaction)->getData();

        $this->assertArrayHasKey('gen_ai.input.messages', $data);
        $this->assertArrayNotHasKey('gen_ai.output.messages', $data);
    }

    public function testNoMessagesAreRecordedWithoutPii(): void
    {
        $transaction = $this->startTransaction();

        $this->classifyWithTypeSafe();

        $this->assertSpanDataContainsNoClassificationContent($this->findEvaluateSpan($transaction)->getData());
    }

    public function testNoMessagesAreRecordedOnFailedSpansWithoutPii(): void
    {
        Http::fake([self::TYPESAFE_URL => Http::response(['error' => 'invalid API key'], 401)]);

        $transaction = $this->startTransaction();

        $this->classifyExpectingFailure();

        $this->assertSpanDataContainsNoClassificationContent($this->findEvaluateSpan($transaction)->getData());
    }

    public function testLongStateIsTruncated(): void
    {
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true]);

        $this->fakeTypeSafe();

        $transaction = $this->startTransaction();

        // Text with spaces, as a long run of letters would be redacted as a base64 blob instead
        Classification::of(str_repeat('word ', 3000))
            ->questions($this->questions())
            ->classify('typesafe');

        $input = json_decode($this->findEvaluateSpan($transaction)->getData()['gen_ai.input.messages'], true);

        $this->assertSame(substr(str_repeat('word ', 3000), 0, 10000) . '...', $input[0]['state']);
    }

    public function testCustomQuestionIsRecordedAsItsArray(): void
    {
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true]);

        $this->fakeTypeSafe();

        $transaction = $this->startTransaction();

        Classification::of('Stripe connect keeps failing')
            ->questions(['custom' => new class implements Question {
                public function toArray(): array
                {
                    return ['type' => 'noul', 'instructions' => 'Is this a custom question?', 'criteria' => ['true' => 'Yes']];
                }
            }])
            ->classify('typesafe');

        $input = json_decode($this->findEvaluateSpan($transaction)->getData()['gen_ai.input.messages'], true);

        $this->assertSame(
            ['type' => 'noul', 'instructions' => 'Is this a custom question?', 'criteria' => ['true' => 'Yes']],
            $input[0]['questions']['custom']
        );
    }

    private function assertSpanDataContainsNoClassificationContent(array $data): void
    {
        $this->assertArrayNotHasKey('gen_ai.input.messages', $data);
        $this->assertArrayNotHasKey('gen_ai.output.messages', $data);

        // The state, questions and answers must not reach the span through any other attribute either
        $encoded = json_encode($data);
        foreach (['Stripe connect', 'convey urgency', 'Payments, invoicing', 'Frustrated but civil', 'probabilities', 'technical'] as $content) {
            $this->assertStringNotContainsString($content, $encoded);
        }
    }

    /**
     * Run a classification from a tool of the given agent, the way a tool would classify the conversation.
     */
    private function classifyInsideAgentTool(Agent $agent): AgentPrompt
    {
        $prompt = $this->agentPrompt($agent);
        $tool = $this->triageTool();

        $this->dispatchLaravelEvent(new PromptingAgent(self::AGENT_INVOCATION_ID, $prompt));
        $this->dispatchLaravelEvent(new InvokingTool(self::AGENT_INVOCATION_ID, 'tool-invocation', $agent, $tool, []));

        $this->classifyWithTypeSafe();

        $this->dispatchLaravelEvent(new ToolInvoked(self::AGENT_INVOCATION_ID, 'tool-invocation', $agent, $tool, [], 'technical', 0.1));

        return $prompt;
    }

    /**
     * @param Agent&RemembersConversationsContract $agent
     */
    private function promptAgent(Agent $agent): void
    {
        $prompt = $this->agentPrompt($agent);

        $this->dispatchLaravelEvent(new PromptingAgent(self::AGENT_INVOCATION_ID, $prompt));
        $this->dispatchLaravelEvent(new AgentPrompted(self::AGENT_INVOCATION_ID, $prompt, $this->agentResponse($agent->currentConversation())));
    }

    private function agentPrompt(Agent $agent): AgentPrompt
    {
        config(['ai.providers.openai.key' => 'test-key']);

        return new AgentPrompt($agent, 'Triage this ticket', [], $this->app->make(AiManager::class)->textProvider('openai'), 'gpt-4o');
    }

    private function agentResponse(string $conversationId): AgentResponse
    {
        return (new AgentResponse(self::AGENT_INVOCATION_ID, 'Routed to the technical team', new TextUsage(), new Meta('openai', 'gpt-4o')))
            ->withinConversation($conversationId);
    }

    /**
     * @return Agent&RemembersConversationsContract
     */
    private function conversationalAgent(): Agent
    {
        return new class implements Agent, RemembersConversationsContract {
            use Promptable;
            use RemembersConversations;

            public function instructions(): string
            {
                return '';
            }
        };
    }

    private function triageTool(): Tool
    {
        return new class implements Tool {
            public function description(): string
            {
                return 'Routes the ticket to a team.';
            }

            public function handle(ToolRequest $request): string
            {
                return 'technical';
            }

            public function schema(JsonSchema $schema): array
            {
                return [];
            }
        };
    }

    private function classifyWithTypeSafe(?string $model = null): ClassificationResponse
    {
        $this->fakeTypeSafe();

        return $this->classify($model);
    }

    /**
     * @param string|array<string, string> $provider
     */
    private function classify(?string $model = null, $provider = 'typesafe'): ClassificationResponse
    {
        return Classification::of('Stripe connect keeps failing, losing sales, help ASAP')
            ->questions($this->questions())
            ->classify($provider, $model);
    }

    private function questions(): array
    {
        return [
            'is_urgent' => new Boolean('Does this message convey urgency?'),
            'department' => new Choice('Which team should handle this?', [
                'billing' => 'Payments, invoicing, refunds',
                'technical' => 'Bugs, outages, integrations',
            ]),
            'frustration' => new Score('How frustrated is the customer?', [
                'Calm', 'Frustrated but civil', 'Very angry',
            ]),
        ];
    }

    private function classifyExpectingFailure(string $provider = 'typesafe'): \Throwable
    {
        try {
            $this->classify(null, $provider);
        } catch (\Throwable $exception) {
            return $exception;
        }

        $this->fail('Expected the classification to fail.');
    }

    private function fakeTypeSafe(): void
    {
        Http::fake([self::TYPESAFE_URL => Http::response($this->typeSafeResponse())]);
    }

    private function typeSafeResponse(): array
    {
        return [
            'model' => 'jev-1.13.0',
            'answers' => [
                'is_urgent' => ['type' => 'noul', 'noul' => 0.92],
                'department' => [
                    'type' => 'choice',
                    'choice' => 'technical',
                    'probabilities' => ['billing' => 0.15, 'technical' => 0.85],
                    'confidence' => 0.82,
                ],
                'frustration' => [
                    'type' => 'score',
                    'score' => 1.6,
                    'legend' => ['0' => 'Calm', '1' => 'Frustrated but civil', '2' => 'Very angry'],
                    'probabilities' => ['0' => 0.05, '1' => 0.3, '2' => 0.65],
                    'confidence' => 0.78,
                ],
            ],
            'usage' => ['input_tokens' => 312, 'output_tokens' => 48],
        ];
    }

    private function findEvaluateSpan(Transaction $transaction): ?Span
    {
        return $this->findSpanByOp($transaction, 'gen_ai.evaluate');
    }

    /**
     * @return Span[]
     */
    private function findEvaluateSpans(Transaction $transaction): array
    {
        return array_values(array_filter($transaction->getSpanRecorder()->getSpans(), function (Span $span): bool {
            return $span->getOp() === 'gen_ai.evaluate';
        }));
    }

    private function findSpanByOp(Transaction $transaction, string $op): ?Span
    {
        foreach ($transaction->getSpanRecorder()->getSpans() as $span) {
            if ($span->getOp() === $op) {
                return $span;
            }
        }

        return null;
    }
}
