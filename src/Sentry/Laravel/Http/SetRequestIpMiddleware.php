<?php

namespace Sentry\Laravel\Http;

use Closure;
use Illuminate\Http\Request;
use Sentry\State\Scope;

class SetRequestIpMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if (app()->bound('sentry')) {
            /** @var \Sentry\State\HubInterface $sentry */
            $sentry = app('sentry');

            $client = $sentry->getClient();

            if ($client !== null && $client->getOptions()->shouldSendDefaultPii()) {
                app('sentry')->configureScope(static function (Scope $scope) use ($request): void {
                    $scope->setUser([
                        'ip_address' => $request->ip(),
                    ]);
                });
            }
        }

        return $next($request);
    }
}
