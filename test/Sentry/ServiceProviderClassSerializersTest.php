<?php

namespace Sentry\Laravel\Tests;

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response as HttpClientResponse;
use Orchestra\Testbench\TestCase;
use Sentry\Laravel\ServiceProvider;
use Sentry\Serializer\RepresentationSerializer;

class ServiceProviderClassSerializersTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('sentry.dsn', 'https://publickey@sentry.dev/123');
    }

    protected function getPackageProviders($app): array
    {
        return [
            ServiceProvider::class,
        ];
    }

    private function makeHttpClientResponse(): HttpClientResponse
    {
        return new HttpClientResponse(new Psr7Response(
            404,
            ['X-Test-Header' => 'test-value'],
            'not found'
        ));
    }

    public function testHttpClientResponseHasADefaultClassSerializerRegistered(): void
    {
        $options = app('sentry')->getClient()->getOptions();

        $classSerializers = $options->getClassSerializers();

        $this->assertArrayHasKey(HttpClientResponse::class, $classSerializers);
    }

    public function testHttpClientResponseIsSerializedWithUsefulInformationInsteadOfARawObjectDump(): void
    {
        $options = app('sentry')->getClient()->getOptions();

        $serializer = new RepresentationSerializer($options);

        $serialized = $serializer->representationSerialize($this->makeHttpClientResponse());

        $this->assertIsArray($serialized);
        $this->assertSame(HttpClientResponse::class, $serialized['class']);
        // The RepresentationSerializer stringifies scalars (by design, it formats
        // values for display), so the status comes back as a string here.
        $this->assertSame('404', $serialized['data']['status']);
        $this->assertSame('not found', $serialized['data']['body']);
        $this->assertArrayHasKey('X-Test-Header', $serialized['data']['headers']);
    }

    public function testUserConfiguredClassSerializerForTheSameClassTakesPrecedenceOverTheDefault(): void
    {
        $this->app['config']->set('sentry.class_serializers', [
            HttpClientResponse::class => static function (HttpClientResponse $response): array {
                return ['custom' => true];
            },
        ]);

        // The client is built (and its options resolved) lazily on first access, so re-resolving
        // it here, after overriding the config, picks up the user's serializer. 'sentry' is only
        // an alias for the singleton stored under HubInterface::class, so both need forgetting.
        $this->app->forgetInstance(\Sentry\State\HubInterface::class);
        $this->app->forgetInstance('sentry');

        $options = app('sentry')->getClient()->getOptions();

        $serializer = new RepresentationSerializer($options);

        $serialized = $serializer->representationSerialize($this->makeHttpClientResponse());

        $this->assertSame(['custom' => 'true'], $serialized['data']);
    }
}
