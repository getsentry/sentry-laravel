<?php

namespace Sentry\Laravel\Util;

/**
 * @internal
 */
class Filesize
{
    /**
     * Convert bytes to human readable format.
     *
     * Credit: https://stackoverflow.com/a/23888858/1580028
     *
     * @param int $bytes
     * @param int $dec
     *
     * @return string
     */
    public static function toHuman(int $bytes, int $dec = 2): string
    {
        $size   = ['B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
        $factor = floor((strlen($bytes) - 1) / 3);

        if ($factor == 0) {
            $dec = 0;
        }

        return sprintf("%.{$dec}f %s", $bytes / (1024 ** $factor), $size[$factor]);

    }
}
