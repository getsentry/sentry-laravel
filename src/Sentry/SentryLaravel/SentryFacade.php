<?php

namespace Sentry\SentryLaravel;

use Illuminate\Support\Facades\Facade;

class SentryFacade extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'sentry';
    }
}
