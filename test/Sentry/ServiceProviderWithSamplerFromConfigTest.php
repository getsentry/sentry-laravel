<?php

namespace Sentry;

use Illuminate\Contracts\Container\Container;
use Sentry\Laravel\Tests\SentryLaravelTestCase;
use Sentry\Laravel\Tests\TestSentryService;
use Sentry\Tracing\SamplingContext;
use Sentry\Tracing\TransactionContext;

class ServiceProviderWithSamplerFromConfigTest extends SentryLaravelTestCase
{

    public function testEmptyTracesSampler(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sampler' => null,
        ]);

        $this->assertSampler(null);
    }

    public function testTracesSamplerWithClosure(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sampler' => function (TransactionContext $context) {
                return 5;
            },
        ]);

        $this->assertSampler(5);
    }

    public function testTracesSamplerWithClosureAndDependencyInjection(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sampler' => function (TransactionContext $context, Container $container) {
                $this->assertSame($this->app->make(Container::class), $container);
                return 5;
            },
        ]);

        $this->assertSampler(5);
    }

    public function testTracesSamplerWithInvalidValue(): void
    {
        $this->expectExceptionMessage('with value array is expected to be of type');
        $this->resetApplicationWithConfig([
            'sentry.traces_sampler' => ['asd'],
        ]);
    }

    public function testTracesSamplerWithStaticMethodInClassButDoesNotExists(): void
    {
        $this->expectExceptionMessage('does not exist');
        $this->resetApplicationWithConfig([
            'sentry.traces_sampler' => ['asd', 'asd'],
        ]);

        $this->assertSampler(null);
    }

    public function testTracesSamplerWithUnknownMethod(): void
    {
        $this->expectExceptionMessage('test() does not exist');
        $this->resetApplicationWithConfig([
            'sentry.traces_sampler' => [TestSentryService::class, 'test'],
        ]);

        $this->assertSampler(null);
    }

    public function testTracesSamplerWithStaticMethod(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sampler' => [TestSentryService::class, 'staticSampler'],
        ]);

        $this->assertSampler(1);
    }

    public function testTracesSamplerWithStaticMethodWithDependencyInjection(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sampler' => [TestSentryService::class, 'staticSamplerDependency'],
        ]);

        $this->assertSampler(2);
    }

    public function testTracesSamplerWithObjectMethod(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sampler' => [TestSentryService::class, 'sampler'],
        ]);

        $this->assertSampler(3);
    }

    public function testTracesSamplerWithObjectMethodWithDependencyInjection(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sampler' => [TestSentryService::class, 'samplerDependency'],
        ]);

        $this->assertSampler(4);
    }

    protected function assertSampler(?float $expected): void
    {
        $options = $this->getHubFromContainer()->getClient()->getOptions();
        $samplingContext = SamplingContext::getDefault(new TransactionContext());

        $tracesSampler = $options->getTracesSampler();

        $sampleRate = null !== $tracesSampler
            ? $tracesSampler($samplingContext)
            : null;

        $this->assertEquals($expected, $sampleRate);
    }

}
