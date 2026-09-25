<?php

namespace Sentry\Laravel\Tests\Features;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Promptable;
use Sentry\Laravel\Tests\TestCase;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\Transaction;

class AiFailedInvocationTest extends TestCase
{
    protected $defaultSetupConfig = ['sentry.tracing.http_client_requests' => false];

    protected function getPackageProviders($app): array
    {
        $providers = parent::getPackageProviders($app);
        if (class_exists(AiServiceProvider::class)) {
            $providers[] = AiServiceProvider::class;
        }

        return $providers;
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(AgentFailed::class)) {
            $this->markTestSkipped('Requires the SDK AgentFailed event.');
        }

        config(['ai.providers.fixture' => [
            'driver' => 'openai-compatible',
            'url' => 'https://model.test/v1',
            'key' => 'fixture-key',
        ]]);
        Http::preventStrayRequests();
    }

    public function testFailureClosesTheInvocationAndRestoresTheParentForTheNextRequest(): void
    {
        Http::fakeSequence()
            ->push($this->answer(), 200)
            ->push(['error' => ['message' => 'upstream failed']], 500)
            ->push($this->answer(), 200);

        $failure = null;
        $this->app['events']->listen(AgentFailed::class, function (AgentFailed $event) use (&$failure): void {
            $failure = $event;
        });
        $transaction = $this->startTransaction();
        $hub = SentrySdk::getCurrentHub();
        $agent = $this->agent();

        $agent->prompt('first', [], 'fixture', 'test-model');
        $this->assertSame($transaction, $hub->getSpan());

        try {
            $agent->prompt('second', [], 'fixture', 'test-model');
            $this->fail('Expected the upstream exception.');
        } catch (RequestException $exception) {
            $this->assertSame(500, $exception->response->status());
        }

        $this->assertSame($transaction, $hub->getSpan());
        $invocations = $this->spans($transaction, 'gen_ai.invoke_agent');
        $this->assertCount(2, $invocations);
        $this->assertEquals(SpanStatus::internalError(), $invocations[1]->getStatus());
        $this->assertNotNull($invocations[1]->getEndTimestamp());

        $this->assertInstanceOf(AgentFailed::class, $failure);
        $beforeRepeat = count($transaction->getSpanRecorder()->getSpans());
        $this->dispatchLaravelEvent($failure);
        $this->assertCount($beforeRepeat, $transaction->getSpanRecorder()->getSpans());
        $this->assertSame($transaction, $hub->getSpan());

        $agent->prompt('third', [], 'fixture', 'test-model');
        $this->assertSame($transaction, $hub->getSpan());
        $this->assertCount(3, $this->spans($transaction, 'gen_ai.chat'));
        foreach ($this->spans($transaction, 'gen_ai.invoke_agent') as $span) {
            $this->assertNotNull($span->getEndTimestamp());
            $this->assertEquals($transaction->getSpanId(), $span->getParentSpanId());
        }
        Http::assertSentCount(3);
    }

    private function agent(): Agent
    {
        return new class implements Agent {
            use Promptable;

            public function instructions(): string
            {
                return '';
            }

            public function maxSteps(): int
            {
                return 1;
            }
        };
    }

    private function answer(): array
    {
        return ['model' => 'test-model', 'choices' => [[
            'message' => ['role' => 'assistant', 'content' => 'ok'],
            'finish_reason' => 'stop',
        ]], 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 5]];
    }

    private function spans(Transaction $transaction, string $operation): array
    {
        return array_values(array_filter($transaction->getSpanRecorder()->getSpans(), function (Span $span) use ($operation): bool {
            return $span->getOp() === $operation;
        }));
    }
}
