<?php

namespace Sentry\Laravel\Tests\Laravel;

use Sentry\DataCollection\DataCollectionOptions;
use Sentry\Laravel\Tests\TestCase;

class LaravelDataCollectionConfigOptionTest extends TestCase
{
    public function testDataCollectionDefaultsToLegacyMode(): void
    {
        $this->assertNull($this->getSentryClientFromContainer()->getOptions()->getDataCollection());

        $this->resetApplicationWithConfig(['sentry.data_collection' => null]);

        $this->assertNull($this->getSentryClientFromContainer()->getOptions()->getDataCollection());
    }

    public function testEmptyConfigurationActivatesSharedDefaults(): void
    {
        $this->resetApplicationWithConfig(['sentry.data_collection' => []]);

        $dataCollection = $this->getDataCollection();

        $this->assertSame('denyList', $dataCollection->getHttpHeaders()['request']['mode']);
        $this->assertSame('denyList', $dataCollection->getHttpHeaders()['response']['mode']);
    }

    public function testCookieConfigurationUsesSharedDefaults(): void
    {
        $this->resetApplicationWithConfig(['sentry.data_collection' => []]);

        $this->assertSame(['mode' => 'denyList', 'terms' => []], $this->getDataCollection()->getCookies());
    }

    public function testCookieConfigurationIsForwardedToThePhpSdk(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [
                'cookies' => ['mode' => 'allowList', 'terms' => ['theme']],
            ],
        ]);

        $this->assertSame(['mode' => 'allowList', 'terms' => ['theme']], $this->getDataCollection()->getCookies());
    }

    public function testInvalidCookieConfigurationUsesSharedResolverFallbacks(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [
                'cookies' => ['mode' => 'invalid', 'terms' => 'invalid'],
            ],
        ]);

        $this->assertSame(['mode' => 'denyList', 'terms' => []], $this->getDataCollection()->getCookies());
    }

    public function testPartialConfigurationRetainsDefaultsForOtherDirection(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [
                'http_headers' => [
                    'request' => ['mode' => 'off'],
                ],
            ],
        ]);

        $headers = $this->getDataCollection()->getHttpHeaders();

        $this->assertSame('off', $headers['request']['mode']);
        $this->assertSame('denyList', $headers['response']['mode']);
    }

    public function testHeaderShorthandIsForwardedToBothDirections(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [
                'http_headers' => ['mode' => 'allowList', 'terms' => ['x-request-id']],
            ],
        ]);

        $headers = $this->getDataCollection()->getHttpHeaders();

        $this->assertSame($headers['request'], $headers['response']);
        $this->assertSame(['mode' => 'allowList', 'terms' => ['x-request-id']], $headers['request']);
    }

    public function testInvalidHeaderConfigurationUsesSharedResolverFallbacks(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [
                'http_headers' => [
                    'request' => ['mode' => 'invalid', 'terms' => 'invalid'],
                ],
            ],
        ]);

        $headers = $this->getDataCollection()->getHttpHeaders();

        $this->assertSame('denyList', $headers['request']['mode']);
        $this->assertSame([], $headers['request']['terms']);
        $this->assertSame('denyList', $headers['response']['mode']);
    }

    /**
     * @dataProvider sendDefaultPiiProvider
     */
    public function testConfiguredPolicyIsIndependentOfSendDefaultPii(bool $sendDefaultPii): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => $sendDefaultPii,
            'sentry.data_collection' => [
                'http_headers' => ['mode' => 'off'],
            ],
        ]);

        $headers = $this->getDataCollection()->getHttpHeaders();

        $this->assertSame('off', $headers['request']['mode']);
        $this->assertSame('off', $headers['response']['mode']);
    }

    public static function sendDefaultPiiProvider(): iterable
    {
        yield [false];
        yield [true];
    }

    private function getDataCollection(): DataCollectionOptions
    {
        $dataCollection = $this->getSentryClientFromContainer()->getOptions()->getDataCollection();

        $this->assertInstanceOf(DataCollectionOptions::class, $dataCollection);

        return $dataCollection;
    }
}
