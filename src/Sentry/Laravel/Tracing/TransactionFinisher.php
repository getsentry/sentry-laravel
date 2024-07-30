<?php

namespace Sentry\Laravel\Tracing;

/**
 * @internal
 */
class TransactionFinisher
{
    public function __construct()
    {
        // We need to finish the transaction after the response has been sent to the client
        // so we register a terminating callback to do so, this allows us to also capture
        // spans that are created during the termination of the application like queue
        // dispatched using dispatch(...)->afterResponse(). This middleware is called
        // before the terminating callbacks so we are 99.9% sure to be the last one
        // to run except if another terminating callback is registered after ours.
        app()->terminating(function () {
            app(Middleware::class)->finishTransaction();
        });
    }
}
