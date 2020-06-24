<?php

namespace Sentry\Laravel;

use Closure;
use Illuminate\Routing\Events\RouteMatched;
use Sentry\State\Scope;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\TransactionContext;
use Sentry\Tracing\Transaction;

class TracingMiddleware
{
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
        $transaction = null;

        if (app()->bound('sentry')) {
            $path = '/' . ltrim($request->path(), '/');

            /** @var \Sentry\State\Hub $hub */
            $hub = app('sentry');

            $context = new TransactionContext();
            $context->op = 'http.server';
            $context->name = $path;
            $context->description = strtoupper($request->method()) . ' ' . $path;
            $context->startTimestamp = $request->server('REQUEST_TIME_FLOAT') ?? microtime(true);

            // Listen for a `RouteMatched` so we can use the route information for a nice transaction name
            // @TODO: We already listen for this in the `EventHandler` but since there is no way for us to change the context this was added as PoC
            app('events')->listen(RouteMatched::class, static function (RouteMatched $event) use (&$context, $request): void {
                $context->name = Integration::extractNameForRoute($event->route);
                $context->description = strtoupper($request->method()) . ' ' . $context->name;
            });

            $transaction = $hub->startTransaction($context);

            $this->addBootTimeSpans($transaction);

            $hub->configureScope(static function (Scope $scope) use ($transaction): void {
                $scope->setSpan($transaction);
            });
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
            /** @var \Sentry\State\Hub $hub */
            $hub = app('sentry');

            $hub->configureScope(static function (Scope $scope): void {
                $transaction = $scope->getSpan();

                if (null !== $transaction) {
                    $transaction->finish();
                }
            });
        }
    }

    private function addBootTimeSpans(Transaction $transaction): void
    {
        if (!defined('LARAVEL_START') || !LARAVEL_START) {
            return;
        }

        if (!defined('SENTRY_AUTOLOAD') || !SENTRY_AUTOLOAD) {
            return;
        }

        if (!defined('SENTRY_BOOTSTRAP') || !SENTRY_BOOTSTRAP) {
            return;
        }

        $spanContextStart = new SpanContext();
        $spanContextStart->op = 'autoload';
        $spanContextStart->endTimestamp = SENTRY_AUTOLOAD;
        $spanContextStart->startTimestamp = LARAVEL_START;
        $transaction->startChild($spanContextStart);

        $spanContextStart = new SpanContext();
        $spanContextStart->op = 'bootstrap';
        $spanContextStart->endTimestamp = SENTRY_BOOTSTRAP;
        $spanContextStart->startTimestamp = SENTRY_AUTOLOAD;
        $transaction->startChild($spanContextStart);
    }
}
