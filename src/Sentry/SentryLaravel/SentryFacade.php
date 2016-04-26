<?php

namespace Sentry\SentryLaravel;

use Illuminate\Support\Facades\Facade;

class SentryFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'sentry';
    }
}
