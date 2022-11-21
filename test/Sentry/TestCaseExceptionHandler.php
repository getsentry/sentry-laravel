<?php

namespace Sentry\Laravel\Tests;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Sentry\Laravel\Integration;

/**
 * This is a proxy class so we can inject the Sentry bits while running tests and handle exceptions like "normal".
 *
 * All type hints are remove from this class to prevent issues when running lower PHP versions where Throwable is not yet a thing.
 */
class TestCaseExceptionHandler implements ExceptionHandler
{
    private $handler;

    public function __construct(ExceptionHandler $handler)
    {
        $this->handler = $handler;
    }

    public function report($e)
    {
        Integration::captureUnhandledException($e);

        $this->handler->report($e);
    }

    public function shouldReport($e)
    {
        return $this->handler->shouldReport($e);
    }

    public function render($request, $e)
    {
        return $this->handler->render($request, $e);
    }

    public function renderForConsole($output, $e)
    {
        $this->handler->render($output, $e);
    }

    public function __call($name, $arguments)
    {
        return call_user_func_array([$this->handler, $name], $arguments);
    }
}
