<?php

namespace Sentry\Laravel\Logs;

use Monolog\Handler\PsrHandler;
use Monolog\LogRecord;

class ExceptionIgnoringPsrHandler extends PsrHandler
{
    public function isHandling(LogRecord $record): bool
    {
        $exception = $record->context['exception'] ?? null;

        if ($exception instanceof \Throwable) {
            return false;
        }

        return parent::isHandling($record);
    }
}
