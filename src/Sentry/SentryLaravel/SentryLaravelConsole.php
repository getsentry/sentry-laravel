<?php

namespace Sentry\SentryLaravel;

trait SentryLaravelConsole
{
    /**
     * Report the exception to the exception handler and sentry.
     *
     * @param \Exception $e
     */
    protected function reportException(\Exception $e)
    {
        app('sentry')->captureException($e);

        app('Illuminate\Contracts\Debug\ExceptionHandler')->report($e);
    }
}