<?php

namespace Sentry\Laravel\Tests\Http;

use Sentry\Event;
use Sentry\Laravel\Http\LaravelRequestFetcher;
use Sentry\Laravel\Tests\TestCase;

class RequestQueryCollectionTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->get('/capture-query', function () {
            \Sentry\captureMessage('capture request query');

            return 'ok';
        });

        $router->get('/capture-explicit-query', function () {
            $event = Event::createEvent();
            $event->setRequest([
                'url' => 'https://explicit.example/path?token=manual',
                'query_string' => 'token=manual',
            ]);
            \Sentry\captureEvent($event);

            return 'ok';
        });
    }

    public function testConfiguredDefaultsFilterRawQueryAndPreserveEncoding(): void
    {
        $this->resetApplicationWithConfig(['sentry.data_collection' => []]);
        $uri = '/capture-query?tag=a&tag=b&%74oken=secret&q=a%20b';

        $this->get($uri)->assertOk();

        $request = $this->getLastSentryEvent()->getRequest();
        $this->assertSame('tag=a&tag=b&%74oken=[Filtered]&q=a%20b', $request['query_string']);
        $this->assertStringEndsWith('?tag=a&tag=b&%74oken=[Filtered]&q=a%20b', $request['url']);
        $this->assertSame($uri, $this->app['request']->getRequestUri());
    }

    public function testQueryCollectionCanBeTurnedOff(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => ['url_query_params' => ['mode' => 'off']],
        ]);

        $this->get('/capture-query?token=secret')->assertOk();

        $request = $this->getLastSentryEvent()->getRequest();
        $this->assertArrayNotHasKey('query_string', $request);
        $this->assertStringNotContainsString('?', $request['url']);
    }

    public function testAllowAndDenyListsStillFilterSensitiveNames(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [
                'url_query_params' => ['mode' => 'allowList', 'terms' => ['visible', 'token']],
            ],
        ]);
        $this->get('/capture-query?visible=yes&other=no&token=secret')->assertOk();
        $this->assertSame('visible=yes&other=[Filtered]&token=[Filtered]', $this->getLastSentryEvent()->getRequest()['query_string']);

        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [
                'url_query_params' => ['mode' => 'denyList', 'terms' => ['private']],
            ],
        ]);
        $this->get('/capture-query?visible=yes&private=no')->assertOk();
        $this->assertSame('visible=yes&private=[Filtered]', $this->getLastSentryEvent()->getRequest()['query_string']);
    }

    public function testLegacyModePreservesExistingRequestIntegrationBehavior(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => null,
            'sentry.send_default_pii' => true,
        ]);

        $this->get('/capture-query?token=secret&q=a%20b')->assertOk();

        $cachedRequest = $this->app->make(LaravelRequestFetcher::CONTAINER_PSR7_INSTANCE_KEY);
        $expectedQuery = $cachedRequest->getUri()->getQuery();
        $request = $this->getLastSentryEvent()->getRequest();
        $this->assertSame($expectedQuery, $request['query_string']);
        $this->assertStringEndsWith('?' . $expectedQuery, $request['url']);
    }

    public function testExplicitRequestQueryDataIsPreserved(): void
    {
        $this->resetApplicationWithConfig(['sentry.data_collection' => []]);

        $this->get('/capture-explicit-query?token=collected')->assertOk();

        $request = $this->getLastSentryEvent()->getRequest();
        $this->assertSame('token=manual', $request['query_string']);
        $this->assertSame('https://explicit.example/path?token=manual', $request['url']);
    }
}
