<?php

declare(strict_types=1);

namespace Sentry\Laravel\Tests;

use Illuminate\Contracts\Container\Container;
use Sentry\ServiceProviderWithSamplerFromConfigTest;
use Sentry\Tracing\SamplingContext;

class TestSentryService
{
    /**
     * @var Container
     */
    protected $container;

    /**
     * @var ServiceProviderWithSamplerFromConfigTest
     */
    static $testCase;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public static function staticSampler(SamplingContext $context): float
    {
        self::$testCase->assertSame(self::$testCase->samplingContext, $context);

        return 1;
    }

    public static function staticSamplerDependency(SamplingContext $context): float
    {
        self::$testCase->assertSame(self::$testCase->samplingContext, $context);

        return 2;
    }

    public function sampler(SamplingContext $context): float
    {
        self::$testCase->assertSame(self::$testCase->samplingContext, $context);

        return 3;
    }

    public function samplerDependency(SamplingContext $context, Container $container): float
    {
        self::$testCase->assertSame(self::$testCase->samplingContext, $context);

        return 4;
    }
}
