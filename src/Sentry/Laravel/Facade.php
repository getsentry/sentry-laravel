<?php

namespace Sentry\Laravel;

use Sentry\State\HubInterface;

/**
 * @method static bool addBreadcrumb(\Sentry\Breadcrumb $breadcrumb)
 * @method static string|null captureMessage(string $message, \Sentry\Severity $level = null, \Sentry\State\Scope $scope = null)
 * @method static string|null captureException(\Throwable $exception)
 * @method static string|null captureEvent(\Throwable $exception)
 * @method static string|null captureLastError()
 * @method static \Sentry\State\Scope pushScope()
 * @method static bool popScope()
 * @method static void configureScope(callable $callback)
 * @method static void withScope(callable $callback)
 * @method static \Sentry\Integration\IntegrationInterface|null getIntegration(string $className)
 * @method static \Sentry\ClientInterface|null getClient()
 * @method static void bindClient(\Sentry\ClientInterface $client)
 * @method static string|null getLastEventId()
 */
class Facade extends \Illuminate\Support\Facades\Facade
{
    protected static function getFacadeAccessor()
    {
        return HubInterface::class;
    }
}
