<?php

namespace Sentry\SentryLaravel;

use Raven_Client;

class SentryLaravel
{
    const VERSION = '0.4.0.dev0';

    /**
     * Get the raven client instance.
     *
     * @param array $user_config
     *
     * @return \Raven_Client
     */
    public static function getClient($user_config)
    {
        $config = array_merge([
            'sdk' => [
                'name' => 'sentry-laravel',
                'version' => self::VERSION,
            ],
        ], $user_config);

        return new Raven_Client($config);
    }
}
