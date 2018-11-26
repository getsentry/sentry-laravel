<?php

namespace Sentry\Laravel;

class Facade extends \Illuminate\Support\Facades\Facade
{
    protected static function getFacadeAccessor()
    {
        return 'sentry';
    }
}
