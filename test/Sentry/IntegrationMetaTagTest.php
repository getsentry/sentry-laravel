<?php

namespace Sentry\Laravel\Tests;

use Mockery;
use Sentry\Laravel\Integration;
use Sentry\State\Scope;
use Sentry\Tracing\Span;

class IntegrationMetaTagTest extends TestCase
{
    private const DANGEROUS_PAYLOAD = '</meta><script>alert("owned")</script>';

    protected function tearDown(): void
    {
        \Sentry\configureScope(static function (Scope $scope): void {
            $scope->setSpan(null);
        });

        parent::tearDown();
    }

    public function testSentryTracingMetaEscapesDangerousTraceparentContent(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 1.0,
        ]);

        $dangerousTraceparent = self::DANGEROUS_PAYLOAD;

        $this->setDangerousSpanValues($dangerousTraceparent, 'safe-baggage');

        $metaTag = Integration::sentryTracingMeta();
        $expected = sprintf(
            '<meta name="sentry-trace" content="%s"/>',
            htmlspecialchars($dangerousTraceparent, ENT_QUOTES, 'UTF-8')
        );

        $this->assertSame($expected, $metaTag);
        $this->assertStringContainsString('&lt;script&gt;', $metaTag);
        $this->assertStringNotContainsString('<script>', $metaTag);
    }

    public function testSentryBaggageMetaEscapesDangerousBaggageContent(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 1.0,
        ]);

        $dangerousBaggage = self::DANGEROUS_PAYLOAD;

        $this->setDangerousSpanValues('safe-traceparent', $dangerousBaggage);

        $metaTag = Integration::sentryBaggageMeta();
        $expected = sprintf(
            '<meta name="baggage" content="%s"/>',
            htmlspecialchars($dangerousBaggage, ENT_QUOTES, 'UTF-8')
        );

        $this->assertSame($expected, $metaTag);
        $this->assertStringContainsString('&lt;script&gt;', $metaTag);
        $this->assertStringNotContainsString('<script>', $metaTag);
    }

    public function testSentryTracingMetaReturnsAWellFormedMetaTag(): void
    {
        $meta = Integration::sentryTracingMeta();
        
        $this->assertStringStartsWith('<meta name="sentry-trace" content="', $meta);
        $this->assertStringEndsWith('"/>', $meta);
    }

    public function testSentryBaggageMetaReturnsAWellFormedMetaTag(): void
    {
        $meta = Integration::sentryBaggageMeta();

        $this->assertStringStartsWith('<meta name="baggage" content="', $meta);
        $this->assertStringEndsWith('"/>', $meta);
    }

    private function setDangerousSpanValues(string $traceparent, string $baggage): void
    {
        $span = Mockery::mock(Span::class);
        $span->shouldReceive('toTraceparent')->andReturn($traceparent)->zeroOrMoreTimes();
        $span->shouldReceive('toBaggage')->andReturn($baggage)->zeroOrMoreTimes();

        \Sentry\configureScope(static function (Scope $scope) use ($span): void {
            $scope->setSpan($span);
        });
    }
}
