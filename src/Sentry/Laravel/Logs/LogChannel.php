<?php

namespace Sentry\Laravel\Logs;

use Monolog\Handler\FingersCrossedHandler;
use Monolog\Logger;
use Illuminate\Log\LogManager;

class LogChannel extends LogManager
{
    public function __invoke(array $config = []): Logger
    {
        $handler = new LogsHandler(
            $config['level'] ?? Logger::DEBUG,
            $config['bubble'] ?? true
        );

        if (isset($config['action_level'])) {
            $handler = new FingersCrossedHandler($handler, $config['action_level']);

            // Consume the `action_level` config option since newer Laravel versions also support this option
            // and will wrap the handler again in another `FingersCrossedHandler` if we leave the option set
            // See: https://github.com/laravel/framework/pull/40305 (release v8.79.0)
            unset($config['action_level']);
        }

        return new Logger(
            $this->parseChannel($config),
            [
                $this->prepareHandler($handler, $config),
            ]
        );
    }
}
