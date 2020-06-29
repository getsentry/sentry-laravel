<?php

namespace Sentry\Laravel;

use Closure;
use Sentry\State\Scope;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\TransactionContext;
use Sentry\Tracing\Transaction;

class TracingMiddleware
{
    /**
     * The current active transaction
     */
    protected $transaction = null;

    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     *
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (app()->bound('sentry')) {
            $path = '/' . ltrim($request->path(), '/');
            $fallbackTime = microtime(true);

            $context = new TransactionContext();
            if ($request->header('sentry-trace')) {
                $context = TransactionContext::fromTraceparent($request->header('sentry-trace'));
            }

            $context->op = 'http.server';
            $context->name = $path;
            $context->data = [
                'url' => $path,
                'method' => strtoupper($request->method()),
            ];
            $context->startTimestamp = $request->server('REQUEST_TIME_FLOAT', $fallbackTime);

            /** @var \Sentry\State\Hub $hub */
            $hub = app('sentry');
            $this->transaction = $hub->startTransaction($context);
            $hub->configureScope(function (Scope $scope): void {
                $scope->setSpan($this->transaction);
            });

            if (!$this->addBootTimeSpans()) {
                // @TODO: We might want to move this together with the `RouteMatches` listener to some central place and or do this from the `EventHandler`
                app()->booted(function () use ($request, $fallbackTime): void {
                    $spanContextStart = new SpanContext();
                    $spanContextStart->op = 'app.bootstrap';
                    $spanContextStart->startTimestamp = defined('LARAVEL_START') ? LARAVEL_START : $request->server('REQUEST_TIME_FLOAT', $fallbackTime);
                    $spanContextStart->endTimestamp = microtime(true);
                    $this->transaction->startChild($spanContextStart);
                });
            }
        }

        return $next($request);
    }

    /**
     * Handle the application termination.
     *
     * @param \Illuminate\Http\Request  $request
     * @param \Illuminate\Http\Response $response
     *
     * @return void
     */
    public function terminate($request, $response)
    {
        if (app()->bound('sentry')) {
            if (null !== $this->transaction) {
                $this->transaction->finish();
            }
        }
    }

    private function addBootTimeSpans(): bool
    {
        if (!defined('LARAVEL_START') || !LARAVEL_START) {
            return false;
        }

        if (!defined('SENTRY_AUTOLOAD') || !SENTRY_AUTOLOAD) {
            return false;
        }

        if (!defined('SENTRY_BOOTSTRAP') || !SENTRY_BOOTSTRAP) {
            return false;
        }

        $spanContextStart = new SpanContext();
        $spanContextStart->op = 'autoload';
        $spanContextStart->startTimestamp = LARAVEL_START;
        $spanContextStart->endTimestamp = SENTRY_AUTOLOAD;
        $this->transaction->startChild($spanContextStart);

        $spanContextStart = new SpanContext();
        $spanContextStart->op = 'bootstrap';
        $spanContextStart->startTimestamp = SENTRY_AUTOLOAD;
        $spanContextStart->endTimestamp = SENTRY_BOOTSTRAP;
        $this->transaction->startChild($spanContextStart);

        return true;
    }
}
