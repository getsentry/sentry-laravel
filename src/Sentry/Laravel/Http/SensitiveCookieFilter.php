<?php

namespace Sentry\Laravel\Http;

use Illuminate\Support\Str;
use Sentry\DataCollection\KeyValueDataFilter;

/**
 * Filters the values of the cookies Laravel uses for sessions and authentication because they are very sensitive.
 *
 * @internal
 */
final class SensitiveCookieFilter
{
    private function __construct()
    {
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    public static function filterValue(string $name, $value)
    {
        if (Str::is([config('session.cookie'), 'remember_*', 'XSRF-TOKEN'], $name)) {
            return KeyValueDataFilter::FILTERED_VALUE;
        }

        return $value;
    }
}
