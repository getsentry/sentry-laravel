<?php

namespace Sentry\Laravel\Tests\Features;

use Illuminate\Routing\Router;
use Sentry\Laravel\Tests\TestCase;

class StrictTraceContinuationIntegrationTest extends TestCase
{
    private const INCOMING_TRACE_ID = '566e3688a61d4bc888951642d6f14a19';
    private const INCOMING_PARENT_SPAN_ID = '566e3688a61d4bc8';
    private const INCOMING_SENTRY_TRACE_HEADER = self::INCOMING_TRACE_ID . '-' . self::INCOMING_PARENT_SPAN_ID . '-1';

    private function registerRoutes(): void
    {
        $this->app['router']->group(['prefix' => 'sentry'], function (Router $router) {
            $router->get('/strict-trace-continuation', function () {
                return 'ok';
            });
        });
    }

    /**
     * @dataProvider strictTraceContinuationDataProvider
     */
    public function testStrictTraceContinuation(?int $orgId, bool $strictTraceContinuation, string $baggage, bool $expectedContinueTrace): void
    {
        $config = [
            'sentry.traces_sample_rate' => 1.0,
            'sentry.strict_trace_continuation' => $strictTraceContinuation,
        ];

        if ($orgId !== null) {
            $config['sentry.org_id'] = $orgId;
        }

        $this->resetApplicationWithConfig($config);
        $this->registerRoutes();

        $server = [
            'HTTP_SENTRY_TRACE' => self::INCOMING_SENTRY_TRACE_HEADER,
        ];

        if ($baggage !== '') {
            $server['HTTP_BAGGAGE'] = $baggage;
        }

        $response = $this->call('GET', '/sentry/strict-trace-continuation', [], [], [], $server);

        $this->assertSame(200, $response->getStatusCode());

        $transaction = $this->getLastSentryEvent();

        $this->assertNotNull($transaction);

        $traceContext = $transaction->getContexts()['trace'];

        if ($expectedContinueTrace) {
            $this->assertSame(self::INCOMING_TRACE_ID, $traceContext['trace_id']);
            $this->assertSame(self::INCOMING_PARENT_SPAN_ID, $traceContext['parent_span_id']);
        } else {
            $this->assertNotSame(self::INCOMING_TRACE_ID, $traceContext['trace_id']);
            $this->assertArrayNotHasKey('parent_span_id', $traceContext);
        }
    }

    public function testConfiguredOrgIdOverridesDsnOrgIdForStrictTraceContinuation(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.dsn' => 'http://publickey@o1.example.com/123',
            'sentry.org_id' => 2,
            'sentry.strict_trace_continuation' => true,
            'sentry.traces_sample_rate' => 1.0,
        ]);
        $this->registerRoutes();

        $response = $this->call('GET', '/sentry/strict-trace-continuation', [], [], [], [
            'HTTP_SENTRY_TRACE' => self::INCOMING_SENTRY_TRACE_HEADER,
            'HTTP_BAGGAGE' => 'sentry-org_id=2',
        ]);

        $this->assertSame(200, $response->getStatusCode());

        $transaction = $this->getLastSentryEvent();

        $this->assertNotNull($transaction);

        $traceContext = $transaction->getContexts()['trace'];

        $this->assertSame(self::INCOMING_TRACE_ID, $traceContext['trace_id']);
        $this->assertSame(self::INCOMING_PARENT_SPAN_ID, $traceContext['parent_span_id']);
    }

    public function testIncomingTraceHeadersPopulatePropagationContextWhenTracingIsDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.enable_tracing' => false,
        ]);
        $this->registerRoutes();

        $response = $this->call('GET', '/sentry/strict-trace-continuation', [], [], [], [
            'HTTP_SENTRY_TRACE' => self::INCOMING_SENTRY_TRACE_HEADER,
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSentryTransactionCount(0);

        $propagationContext = $this->getCurrentSentryScope()->getPropagationContext();

        $this->assertSame(self::INCOMING_TRACE_ID, (string) $propagationContext->getTraceId());
        $this->assertSame(self::INCOMING_PARENT_SPAN_ID, (string) $propagationContext->getParentSpanId());
    }

    public static function strictTraceContinuationDataProvider(): \Generator
    {
        yield [1, false, 'sentry-org_id=1', true];
        yield [1, false, '', true];
        yield [null, false, 'sentry-org_id=1', true];
        yield [null, false, '', true];
        yield [2, false, 'sentry-org_id=1', false];
        yield [1, true, 'sentry-org_id=1', true];
        yield [1, true, '', false];
        yield [null, true, 'sentry-org_id=1', false];
        yield [null, true, '', true];
        yield [2, true, 'sentry-org_id=1', false];
    }
}
