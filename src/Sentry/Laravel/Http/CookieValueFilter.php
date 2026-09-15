<?php

namespace Sentry\Laravel\Http;

use Illuminate\Support\Str;
use Sentry\DataCollection\KeyValueDataFilter;

/**
 * Filters cookie values that Laravel considers too sensitive to collect.
 *
 * @internal
 */
final class CookieValueFilter
{
    private function __construct()
    {
    }

    /**
     * @param array<array-key, mixed> $cookies
     *
     * @return array<array-key, mixed>
     */
    public static function filter(array $cookies): array
    {
        foreach ($cookies as $name => $value) {
            $cookies[$name] = self::filterValue((string) $name, $value);
        }

        return $cookies;
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
