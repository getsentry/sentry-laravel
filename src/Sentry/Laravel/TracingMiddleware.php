<?php

namespace Sentry\Laravel;

use Closure;
use Sentry\State\Scope;
use Sentry\Tracing\TransactionContext;

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
            $context->startTimestamp = $this->app['request']->server('REQUEST_TIME_FLOAT') ?? microtime(true);
            $context->name = $request->path();
            $context->op = 'request';
            $transaction = $hub->startTransaction($context);
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
}
