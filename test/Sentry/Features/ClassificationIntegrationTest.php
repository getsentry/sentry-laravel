<?php

namespace Sentry\Laravel\Tests\Features;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;
use Laravel\Ai\Events\Classifying;
use Laravel\Ai\Responses\ClassificationResponse;
use Sentry\Laravel\Tests\TestCase;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\Transaction;

class ClassificationIntegrationTest extends TestCase
{
    private const TYPESAFE_URL = 'https://api.typesafe.ai/v1/systemone';

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

    private function classifyWithTypeSafe(?string $model = null): ClassificationResponse
    {
        $this->fakeTypeSafe();

        return $this->classify($model);
    }

    private function classify(?string $model = null): ClassificationResponse
    {
        return Classification::of('Stripe connect keeps failing, losing sales, help ASAP')
            ->questions([
                'is_urgent' => new Boolean('Does this message convey urgency?'),
                'department' => new Choice('Which team should handle this?', [
                    'billing' => 'Payments, invoicing, refunds',
                    'technical' => 'Bugs, outages, integrations',
                ]),
                'frustration' => new Score('How frustrated is the customer?', [
                    'Calm', 'Frustrated but civil', 'Very angry',
                ]),
            ])
            ->classify('typesafe', $model);
    }

    private function fakeTypeSafe(): void
    {
        Http::fake([
            self::TYPESAFE_URL => Http::response([
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
            ]),
        ]);
    }

    private function findEvaluateSpan(Transaction $transaction): ?Span
    {
        return $this->findSpanByOp($transaction, 'gen_ai.evaluate');
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
