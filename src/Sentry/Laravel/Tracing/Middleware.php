<?php

namespace Sentry\Laravel\Tracing;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Sentry\Laravel\Integration;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\TransactionContext;

class Middleware
{
    /**
     * The current active transaction.
     *
     * @var \Sentry\Tracing\Transaction|null
     */
    protected $transaction;

    /**
     * The span for the `app.handle` part of the application.
     *
     * @var \Sentry\Tracing\Span|null
     */
    protected $appSpan;

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
            $this->startTransaction($request, app('sentry'));
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
    public function terminate($request, $response): void
    {
        if ($this->transaction !== null && app()->bound('sentry')) {
            if ($this->appSpan !== null) {
                $this->appSpan->finish();
            }

            // Make sure we set the transaction and not have a child span in the Sentry SDK
            // If the transaction is not on the scope during finish, the trace.context is wrong
            SentrySdk::getCurrentHub()->setSpan($this->transaction);

            if ($request instanceof Request) {
                $this->hydrateRequestData($request);
            }

            if ($response instanceof Response) {
                $this->hydrateResponseData($response);
            }

            $this->transaction->finish();
        }
    }

    private function startTransaction(Request $request, HubInterface $sentry): void
    {
        $path = '/' . ltrim($request->path(), '/');
        $fallbackTime = microtime(true);
        $sentryTraceHeader = $request->header('sentry-trace');

        $context = $sentryTraceHeader
            ? TransactionContext::fromTraceparent($sentryTraceHeader)
            : new TransactionContext;

        $context->setOp('http.server');
        $context->setName($path);
        $context->setData([
            'url' => $path,
            'method' => strtoupper($request->method()),
        ]);
        $context->setStartTimestamp($request->server('REQUEST_TIME_FLOAT', $fallbackTime));

        $this->transaction = $sentry->startTransaction($context);

        // Setting the Transaction on the Hub
        SentrySdk::getCurrentHub()->setSpan($this->transaction);

        if (!$this->addBootTimeSpans()) {
            // @TODO: We might want to move this together with the `RouteMatches` listener to some central place and or do this from the `EventHandler`
            app()->booted(function () use ($request, $fallbackTime): void {
                $spanContextStart = new SpanContext();
                $spanContextStart->setOp('app.bootstrap');
                $spanContextStart->setStartTimestamp(defined('LARAVEL_START') ? LARAVEL_START : $request->server('REQUEST_TIME_FLOAT', $fallbackTime));
                $spanContextStart->setEndTimestamp(microtime(true));
                $this->transaction->startChild($spanContextStart);

                $appContextStart = new SpanContext();
                $appContextStart->setOp('app.handle');
                $appContextStart->setStartTimestamp(microtime(true));
                $this->appSpan = $this->transaction->startChild($appContextStart);

                SentrySdk::getCurrentHub()->setSpan($this->appSpan);
            });
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
        $spanContextStart->setOp('autoload');
        $spanContextStart->setStartTimestamp(LARAVEL_START);
        $spanContextStart->setEndTimestamp(SENTRY_AUTOLOAD);
        $this->transaction->startChild($spanContextStart);

        $spanContextStart = new SpanContext();
        $spanContextStart->setOp('bootstrap');
        $spanContextStart->setStartTimestamp(SENTRY_AUTOLOAD);
        $spanContextStart->setEndTimestamp(SENTRY_BOOTSTRAP);
        $this->transaction->startChild($spanContextStart);

        return true;
    }

    private function hydrateRequestData(Request $request): void
    {
        $route = $request->route();

        if ($route instanceof Route) {
            $routeName = Integration::extractNameForRoute($route) ?? '<unlabeled transaction>';

            $this->transaction->setName($routeName);
            $this->transaction->setData([
                'name' => $route->getName(),
                'action' => $route->getActionName(),
                'method' => $request->getMethod(),
            ]);
        }
    }

    private function hydrateResponseData(Response $response): void
    {
        $this->transaction->setHttpStatus($response->status());
    }
}
