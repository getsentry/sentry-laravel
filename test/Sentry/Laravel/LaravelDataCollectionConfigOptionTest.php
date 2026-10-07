<?php

namespace Sentry\Laravel\Tests\Laravel;

use Sentry\DataCollection\DataCollectionOptions;
use Sentry\DataCollection\HttpMessageType;
use Sentry\DataCollection\KeyValueCollectionBehavior;
use Sentry\Laravel\Tests\TestCase;
use Sentry\State\HubInterface;

class LaravelDataCollectionConfigOptionTest extends TestCase
{
    public function testDataCollectionIsNullByDefault(): void
    {
        $this->assertNull(config('sentry.data_collection'));
        $this->assertNull($this->getDataCollectionOptions());
    }

    public function testEmptyDataCollectionAppliesTheDefaults(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
        ]);

        $dataCollection = $this->getDataCollectionOptions();

        $this->assertNotNull($dataCollection);
        $this->assertTrue($dataCollection->shouldCollectUserInfo());
        $this->assertEquals(KeyValueCollectionBehavior::denyList(), $dataCollection->getCookies());
        $this->assertEquals([
            'request' => KeyValueCollectionBehavior::denyList(),
            'response' => KeyValueCollectionBehavior::denyList(),
        ], $dataCollection->getHttpHeaders());
        $this->assertSame([
            HttpMessageType::incomingRequest(),
            HttpMessageType::outgoingRequest(),
            HttpMessageType::incomingResponse(),
            HttpMessageType::outgoingResponse(),
        ], $dataCollection->getHttpBodies());
        $this->assertEquals(KeyValueCollectionBehavior::denyList(), $dataCollection->getUrlQueryParams());
        $this->assertSame(['inputs' => true, 'outputs' => true], $dataCollection->getGenAi());
        $this->assertTrue($dataCollection->shouldCollectDatabaseQueryData());
        $this->assertTrue($dataCollection->shouldCollectQueues());
        $this->assertEquals(KeyValueCollectionBehavior::denyList(), $dataCollection->getStackFrameVariables());
        $this->assertSame(5, $dataCollection->getFrameContextLines());
    }

    public function testDataCollectionIsResolvedFromConfig(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [
                'user_info' => false,
                'cookies' => [
                    'mode' => 'allowList',
                    'terms' => ['theme'],
                ],
                'http_headers' => [
                    'request' => [
                        'mode' => 'denyList',
                        'terms' => ['x-forwarded-for'],
                    ],
                    'response' => [
                        'mode' => 'off',
                    ],
                ],
                'http_bodies' => ['incomingRequest', 'outgoingRequest'],
                'url_query_params' => [
                    'terms' => ['email'],
                ],
                'gen_ai' => [
                    'inputs' => false,
                    'outputs' => true,
                ],
                'database_query_data' => false,
                'queues' => false,
                'stack_frame_variables' => [
                    'mode' => 'allowList',
                    'terms' => ['id'],
                ],
                'frame_context_lines' => 3,
            ],
        ]);

        $dataCollection = $this->getDataCollectionOptions();

        $this->assertNotNull($dataCollection);
        $this->assertFalse($dataCollection->shouldCollectUserInfo());
        $this->assertEquals(KeyValueCollectionBehavior::allowList(['theme']), $dataCollection->getCookies());
        $this->assertEquals([
            'request' => KeyValueCollectionBehavior::denyList(['x-forwarded-for']),
            'response' => KeyValueCollectionBehavior::off(),
        ], $dataCollection->getHttpHeaders());
        $this->assertSame([HttpMessageType::incomingRequest(), HttpMessageType::outgoingRequest()], $dataCollection->getHttpBodies());
        $this->assertEquals(KeyValueCollectionBehavior::denyList(['email']), $dataCollection->getUrlQueryParams());
        $this->assertSame(['inputs' => false, 'outputs' => true], $dataCollection->getGenAi());
        $this->assertFalse($dataCollection->shouldCollectDatabaseQueryData());
        $this->assertFalse($dataCollection->shouldCollectQueues());
        $this->assertEquals(KeyValueCollectionBehavior::allowList(['id']), $dataCollection->getStackFrameVariables());
        $this->assertSame(3, $dataCollection->getFrameContextLines());
    }

    public function testDataCollectionShorthandsAreExpanded(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [
                'http_headers' => [
                    'mode' => 'allowList',
                    'terms' => ['x-request-id'],
                ],
                'http_bodies' => [],
                'stack_frame_variables' => false,
            ],
        ]);

        $dataCollection = $this->getDataCollectionOptions();

        $this->assertNotNull($dataCollection);
        $this->assertEquals([
            'request' => KeyValueCollectionBehavior::allowList(['x-request-id']),
            'response' => KeyValueCollectionBehavior::allowList(['x-request-id']),
        ], $dataCollection->getHttpHeaders());
        $this->assertSame([], $dataCollection->getHttpBodies());
        $this->assertEquals(KeyValueCollectionBehavior::off(), $dataCollection->getStackFrameVariables());
    }

    private function getDataCollectionOptions(): ?DataCollectionOptions
    {
        return app(HubInterface::class)->getClient()->getOptions()->getDataCollection();
    }
}
