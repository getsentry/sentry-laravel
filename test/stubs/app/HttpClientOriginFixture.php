<?php

namespace App;

use Illuminate\Support\Facades\Http;

/**
 * The SDK never considers frames from the `Sentry\` namespace to be in-app, which includes our test classes,
 * so requests made directly from a test can never resolve their origin to the test itself.
 */
class HttpClientOriginFixture
{
    public function get(string $url): void
    {
        Http::get($url);
    }
}
