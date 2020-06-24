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
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $transaction = null;

        if (app()->bound('sentry')) {
            /** @var \Sentry\State\Hub $hub */
            $hub = app('sentry');
            $context = new TransactionContext();
            $context->startTimestamp = $request->server('REQUEST_TIME_FLOAT') ?? microtime(true);
            $path = '/' . $request->path();
            $context->name = $path;
            $context->description = strtoupper($request->method()) . ' ' . $path;
            $context->op = 'http.server';
            $transaction = $hub->startTransaction($context);
            $this->addBootTimeSpans($transaction);
            $hub->configureScope(function (Scope $scope) use ($transaction): void {
                $scope->setSpan($transaction);
            });
        }

        $response = $next($request);

        if (null !== $transaction) {
            $transaction->finish();
        }

        return $response;
    }

    private function addBootTimeSpans(Transaction $transaction): void
    {
        if (LARAVEL_START && SENTRY_AUTOLOAD && SENTRY_BOOTSTRAP) {
            $spanContextStart = new SpanContext();
            $spanContextStart->op = 'autoload';
            $spanContextStart->startTimestamp = LARAVEL_START;
            $spanContextStart->endTimestamp = SENTRY_AUTOLOAD;
            $transaction->startChild($spanContextStart);

            $spanContextStart = new SpanContext();
            $spanContextStart->op = 'bootstrap';
            $spanContextStart->startTimestamp = SENTRY_AUTOLOAD;
            $spanContextStart->endTimestamp = SENTRY_BOOTSTRAP;
            $transaction->startChild($spanContextStart);
        }
    }
}
