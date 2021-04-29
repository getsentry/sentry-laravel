<?php

namespace Sentry\Laravel;

use Monolog\Logger;
use Illuminate\Log\LogManager;
use Sentry\State\HubInterface;

class LogChannel extends LogManager
{
    /**
     * @param array $config
     *
     * @return Logger
     */
    public function __invoke(array $config): Logger
    {
        $handler = new SentryHandler(
            $this->app->make(HubInterface::class),
            $config['level'] ?? Logger::DEBUG,
            $config['bubble'] ?? true,
            $config['report_exceptions'] ?? true,
            isset($config['formatter']) && $config['formatter'] !== 'default'
        );

        return new Logger(
            $this->parseChannel($config),
            [
                $this->prepareHandler($handler, $config),
            ]
        );
    }
}
