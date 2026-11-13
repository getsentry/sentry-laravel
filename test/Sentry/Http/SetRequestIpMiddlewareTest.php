<?php

namespace Sentry\Laravel\Tests\Http;

use Illuminate\Http\Request;
use Sentry\Laravel\Http\SetRequestIpMiddleware;
use Sentry\Laravel\Tests\TestCase;

class SetRequestIpMiddlewareTest extends TestCase
{
    public function testIpAddressIsSetByDefault(): void
    {
        $this->handleRequest();

        $this->assertSame('1.2.3.4', $this->getCurrentSentryScope()->getUser()->getIpAddress());
    }

    public function testIpAddressIsNotSetWhenUserInfoIsDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [
                'user_info' => false,
            ],
        ]);

        $this->handleRequest();

        $this->assertNull($this->getCurrentSentryScope()->getUser());
    }

    private function handleRequest(): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '1.2.3.4']);

        (new SetRequestIpMiddleware)->handle($request, static function () {
        });
    }
}
