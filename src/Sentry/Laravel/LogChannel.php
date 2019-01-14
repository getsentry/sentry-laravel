<?php

namespace Sentry\Laravel;

use Illuminate\Log\LogManager;
use Monolog\Logger;

class LogChannel extends LogManager
{
    /**
     * @param array $config
     *
     * @return Logger
     */
    public function __invoke(array $config)
    {
        $handler = new SentryHandler(
            $this->app->make('sentry'),
            isset($config['level']) ? $config['level'] : null,
            isset($config['bubble']) ? $config['bubble'] : true
        );

        return new Logger($this->parseChannel($config), [$this->prepareHandler($handler, $config)]);
    }
}
