<?php

namespace Sentry\Laravel\Tests\Http;

use Sentry\Event;
use Sentry\Laravel\Tests\TestCase;

class RequestCookieCollectionTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->get('/capture-cookies', function () {
            \Sentry\captureMessage('capture request cookies');

            return 'ok';
        });

        $router->get('/capture-explicit-cookies', function () {
            $event = Event::createEvent();
            $event->setRequest(['cookies' => []]);
            \Sentry\captureEvent($event);

            return 'ok';
        });
    }

    public function testConfiguredDefaultsCollectRequestCookies(): void
    {
        $this->resetApplicationWithConfig(['sentry.data_collection' => []]);

        $this->withUnencryptedCookie('theme', 'dark')->get('/capture-cookies');

        $this->assertSame('dark', $this->capturedCookies()['theme']);
    }

    public function testConfiguredDefaultsFilterSensitiveRequestCookies(): void
    {
        $this->resetApplicationWithConfig(['sentry.data_collection' => []]);

        $this->withUnencryptedCookie('session_id', 'secret')->get('/capture-cookies');

        $this->assertSame('[Filtered]', $this->capturedCookies()['session_id']);
    }

    public function testLaravelRememberCookiesRemainFiltered(): void
    {
        $this->resetApplicationWithConfig(['sentry.data_collection' => []]);

        $this->withUnencryptedCookie('remember_web', 'secret')->get('/capture-cookies');

        $this->assertSame('[Filtered]', $this->capturedCookies()['remember_web']);
    }

    public function testRequestCookieCollectionCanBeTurnedOff(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => ['cookies' => ['mode' => 'off']],
        ]);

        $this->withCookie('theme', 'dark')->get('/capture-cookies');

        $this->assertArrayNotHasKey('cookies', $this->getLastSentryEvent()->getRequest());
    }

    public function testRequestCookieAllowListFiltersUnlistedCookies(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [
                'cookies' => ['mode' => 'allowList', 'terms' => ['theme']],
            ],
        ]);

        $this->withUnencryptedCookie('locale', 'en')->get('/capture-cookies');

        $this->assertSame('[Filtered]', $this->capturedCookies()['locale']);
    }

    public function testLegacyCookieCollectionStillUsesSendDefaultPii(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => null,
            'sentry.send_default_pii' => false,
        ]);

        $this->withUnencryptedCookie('theme', 'dark')->get('/capture-cookies');

        $this->assertArrayNotHasKey('cookies', $this->getLastSentryEvent()->getRequest());
    }

    public function testConfiguredPolicyOverridesEnabledSendDefaultPii(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => ['cookies' => ['mode' => 'off']],
            'sentry.send_default_pii' => true,
        ]);

        $this->withUnencryptedCookie('theme', 'dark')->get('/capture-cookies');

        $this->assertArrayNotHasKey('cookies', $this->getLastSentryEvent()->getRequest());
    }

    public function testLegacyPiiCollectionStillCapturesCookies(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => null,
            'sentry.send_default_pii' => true,
        ]);

        $this->withUnencryptedCookie('theme', 'dark')->get('/capture-cookies');

        $this->assertSame('dark', $this->capturedCookies()['theme']);
    }

    public function testExplicitRequestCookiesArePreserved(): void
    {
        $this->resetApplicationWithConfig(['sentry.data_collection' => []]);

        $this->withUnencryptedCookie('theme', 'dark')->get('/capture-explicit-cookies');

        $this->assertSame([], $this->capturedCookies());
    }

    /** @return array<string, mixed> */
    private function capturedCookies(): array
    {
        return $this->getLastSentryEvent()->getRequest()['cookies'];
    }
}
