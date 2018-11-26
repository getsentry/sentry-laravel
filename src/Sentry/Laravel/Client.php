<?php

namespace Sentry\Laravel;

class Client extends \Sentry\Client
{
    /**
     * The version of the library.
     */
    public const VERSION = '1.0.x-dev';

    /**
     * The identifier of the SDK.
     */
    public const SDK_IDENTIFIER = 'sentry.php.laravel';
}
