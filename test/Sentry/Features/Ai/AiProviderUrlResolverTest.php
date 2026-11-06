<?php

namespace Sentry\Laravel\Tests\Features\Ai;

use Laravel\Ai\Contracts\Gateway\Gateway;
use Laravel\Ai\Providers\OpenAiProvider;
use Laravel\Ai\Providers\Provider;
use Mockery;
use Sentry\Laravel\Features\Ai\AiProviderUrlResolver;
use Sentry\Laravel\Tests\TestCase;

class AiProviderUrlResolverTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Provider::class)) {
            $this->markTestSkipped('The laravel/ai package is not installed.');
        }

        parent::setUp();
    }

    public function testConfiguredUrlIsUsed(): void
    {
        config(['prism.providers.openai.url' => 'https://prism.test/v1']);

        $provider = $this->makeProvider(['url' => 'https://proxy.test/v1']);

        $this->assertSame('https://proxy.test/v1', AiProviderUrlResolver::baseUrl($provider));
    }

    public function testPrismUrlIsUsedWithoutConfiguredUrl(): void
    {
        config(['prism.providers.openai.url' => 'https://prism.test/v1']);

        $this->assertSame('https://prism.test/v1', AiProviderUrlResolver::baseUrl($this->makeProvider()));
    }

    public function testKnownDriverFallsBackToItsDefaultUrl(): void
    {
        $this->assertSame('https://api.openai.com/v1', AiProviderUrlResolver::baseUrl($this->makeProvider()));
    }

    public function testUnknownDriverHasNoUrl(): void
    {
        $this->assertNull(AiProviderUrlResolver::baseUrl($this->makeProvider(['driver' => 'custom'])));
    }

    public function testOnlyProvidersHaveUrls(): void
    {
        $this->assertNull(AiProviderUrlResolver::baseUrl(new \stdClass()));
    }

    public function testHostIsTakenFromTheConfiguredUrl(): void
    {
        $this->assertSame('proxy.test', AiProviderUrlResolver::host($this->makeProvider(['url' => 'https://Proxy.test/v1'])));
    }

    public function testHostFallsBackToTheDefaultUrl(): void
    {
        $this->assertSame('api.typesafe.ai', AiProviderUrlResolver::host($this->makeProvider(['driver' => 'typesafe'])));
    }

    public function testConfiguredUrlWithoutSchemeHasNoHost(): void
    {
        // The broken configured URL is still the one requests go to, so the default must not be used instead
        $this->assertNull(AiProviderUrlResolver::host($this->makeProvider(['driver' => 'typesafe', 'url' => 'api.typesafe.ai/v1'])));
    }

    public function testUnknownDriverHasNoHost(): void
    {
        $this->assertNull(AiProviderUrlResolver::host($this->makeProvider(['driver' => 'custom'])));
    }

    private function makeProvider(array $config = []): OpenAiProvider
    {
        return new OpenAiProvider(
            Mockery::mock(Gateway::class),
            array_merge(['name' => 'openai', 'driver' => 'openai', 'key' => 'test-key'], $config),
            $this->app['events']
        );
    }
}
