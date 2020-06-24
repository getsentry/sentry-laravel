<?php

namespace Sentry\Laravel;

use Closure;
use Sentry\SentrySdk;
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

        return $next($request);
    }

    public function terminate($request, $response)
    {
        /** @var \Sentry\State\Hub $hub */
        $hub = SentrySdk::getCurrentHub();
        $hub->configureScope(function (Scope $scope): void {
            $transaction = $scope->getSpan();
            if (null !== $transaction) {
                $transaction->finish();
            }
        });
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
