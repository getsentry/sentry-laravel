<?php

namespace Sentry\Laravel\Tests\Http;

use Sentry\Event;
use Sentry\Laravel\Tests\TestCase;

class RequestHeaderCollectionTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->get('/capture-headers', function () {
            \Sentry\captureMessage('capture request headers');

            return 'ok';
        });

        $router->get('/capture-explicit-headers', function () {
            $event = Event::createEvent();
            $event->setRequest(['headers' => []]);
            \Sentry\captureEvent($event);

            return 'ok';
        });
    }

    public function testConfiguredDefaultsCollectRequestHeadersWithTracingDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => null,
            'sentry.data_collection' => [],
        ]);

        $this->get('/capture-headers', [
            'X-Request-Id' => 'request-id',
            'Authorization' => 'Bearer secret',
            'Cookie' => 'session=secret',
        ]);

        $headers = $this->getLastSentryEvent()->getRequest()['headers'];

        $this->assertSame(['request-id'], $headers['x-request-id']);
        $this->assertSame(['[Filtered]'], $headers['authorization']);
        $this->assertArrayNotHasKey('cookie', $headers);
        $this->assertSame('request-id', $this->app['request']->headers->get('X-Request-Id'));
    }

    public function testRequestHeaderCollectionCanBeTurnedOffIndependently(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [
                'http_headers' => [
                    'request' => ['mode' => 'off'],
                    'response' => ['mode' => 'denyList'],
                ],
            ],
        ]);

        $this->get('/capture-headers', ['X-Request-Id' => 'request-id']);

        $this->assertArrayNotHasKey('headers', $this->getLastSentryEvent()->getRequest());
    }

    public function testAllowListAndDenyListAreAppliedBySharedCollector(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [
                'http_headers' => ['mode' => 'allowList', 'terms' => ['x-request-id']],
            ],
        ]);

        $this->get('/capture-headers', [
            'X-Request-Id' => 'request-id',
            'X-Other' => 'other',
        ]);

        $headers = $this->getLastSentryEvent()->getRequest()['headers'];
        $this->assertSame(['request-id'], $headers['x-request-id']);
        $this->assertSame(['[Filtered]'], $headers['x-other']);
    }

    public function testLegacyAuthorizationBehaviorStillUsesSendDefaultPii(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => null,
            'sentry.send_default_pii' => false,
        ]);
        $this->get('/capture-headers', ['Authorization' => 'Bearer secret']);
        $this->assertSame(['[Filtered]'], $this->getLastSentryEvent()->getRequest()['headers']['authorization']);

        $this->resetApplicationWithConfig([
            'sentry.data_collection' => null,
            'sentry.send_default_pii' => true,
        ]);
        $this->get('/capture-headers', ['Authorization' => 'Bearer secret']);
        $this->assertSame(['Bearer secret'], $this->getLastSentryEvent()->getRequest()['headers']['authorization']);
    }

    public function testExplicitRequestHeadersArePreserved(): void
    {
        $this->resetApplicationWithConfig(['sentry.data_collection' => []]);

        $this->get('/capture-explicit-headers', ['X-Request-Id' => 'request-id']);

        $this->assertSame([], $this->getLastSentryEvent()->getRequest()['headers']);
    }

    public function testDisablingDefaultIntegrationsPreventsRequestEnrichment(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
            'sentry.default_integrations' => false,
        ]);

        $this->get('/capture-headers', ['X-Request-Id' => 'request-id']);

        $this->assertSame([], $this->getLastSentryEvent()->getRequest());
    }
}
