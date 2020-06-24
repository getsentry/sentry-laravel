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
            $fallbackTime = microtime(true);

            /** @var \Sentry\State\Hub $hub */
            $hub = app('sentry');

            $context = new TransactionContext();
            $context->op = 'http.server';
            $context->name = $path;
            $context->description = strtoupper($request->method()) . ' ' . $path;
            $context->startTimestamp = $request->server('REQUEST_TIME_FLOAT', $fallbackTime);

            // Listen for a `RouteMatched` so we can use the route information for a nice transaction name
            // @TODO: We already listen for this in the `EventHandler` but since there is no way for us to change the context this was added as PoC
            app('events')->listen(RouteMatched::class, static function (RouteMatched $event) use (&$context, $request): void {
                $context->name = Integration::extractNameForRoute($event->route);
                $context->description = strtoupper($request->method()) . ' ' . $context->name;
            });

            $transaction = $hub->startTransaction($context);

            if (!$this->addBootTimeSpans($transaction)) {
                // @TODO: We might want to move this together with the `RouteMatches` listener to some central place and or do this from the `EventHandler`
                app()->booted(static function () use ($request, $transaction, $fallbackTime): void {
                    $spanContextStart = new SpanContext();
                    $spanContextStart->op = 'autoload+bootstrap';
                    $spanContextStart->startTimestamp = defined('LARAVEL_START') ? LARAVEL_START : $request->server('REQUEST_TIME_FLOAT', $fallbackTime);
                    $spanContextStart->endTimestamp = microtime(true);
                    $transaction->startChild($spanContextStart);
                });
            }

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

    private function addBootTimeSpans(Transaction $transaction): bool
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
        $transaction->startChild($spanContextStart);

        $spanContextStart = new SpanContext();
        $spanContextStart->op = 'bootstrap';
        $spanContextStart->startTimestamp = SENTRY_AUTOLOAD;
        $spanContextStart->endTimestamp = SENTRY_BOOTSTRAP;
        $transaction->startChild($spanContextStart);
    }
}
