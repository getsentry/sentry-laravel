<?php

namespace Sentry\Laravel\Tests;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Sentry\Laravel\Integration;
use Throwable;

class TestCaseExceptionHandler implements ExceptionHandler
{
    private $handler;

    public function __construct(ExceptionHandler $handler)
    {
        $this->handler = $handler;
    }

    public function report(Throwable $e)
    {
        Integration::captureUnhandledException($e);

        $this->handler->report($e);
    }

    public function shouldReport(Throwable $e)
    {
        return $this->handler->shouldReport($e);
    }

    public function render($request, Throwable $e)
    {
        return $this->handler->render($request, $e);
    }

    public function renderForConsole($output, Throwable $e)
    {
        $this->handler->render($output, $e);
    }

    public function __call($name, $arguments)
    {
        return call_user_func_array([$this->handler, $name], $arguments);
    }
}
