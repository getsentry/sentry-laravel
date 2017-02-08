<?php

namespace Sentry\SentryLaravel;

use Exception;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Route;
use Raven_Client;

class SentryLaravelEventHandler
{

    /**
     * Maps event handler function to event names.
     *
     * @var array
     */
    protected static $eventHandlerMap = [
        'router.matched'                           => 'routerMatched', // Until Laravel 5.1
        'Illuminate\Routing\Events\RouteMatched'   => 'routeMatched',  // Since Laravel 5.2

        'illuminate.query'                         => 'query',         // Until Laravel 5.1
        'Illuminate\Database\Events\QueryExecuted' => 'queryExecuted', // Since Laravel 5.2

        'illuminate.log'                           => 'log',           // Until Laravel 5.3
        'Illuminate\Log\Events\MessageLogged'      => 'messageLogged', // Since Laravel 5.4
    ];

    /**
     * Event recorder.
     *
     * @var Raven_Client
     */
    protected $client;

    /**
     * @param Raven_Client $client
     * @param array        $config
     */
    public function __construct(Raven_Client $client, array $config)
    {
        $this->client = $client;
        $this->sqlBindings = isset($config['breadcrumbs.sql_bindings'])
            ? $config['breadcrumbs.sql_bindings']
            : true;
    }

    /**
     * Attach all event handlers.
     *
     * @param Dispatcher $events
     */
    public function subscribe(Dispatcher $events)
    {
        foreach (static::$eventHandlerMap as $eventName => $handler) {
            $events->listen($eventName, [$this, $handler]);
        }
    }

    /**
     * Pass through the event and capture any errors.
     *
     * @param $method
     * @param $arguments
     */
    public function __call($method, $arguments)
    {
        try {
            call_user_func_array([$this, $method . 'handler'], $arguments);
        } catch (Exception $exception) {
            // Ignore
        }
    }

    /**
     * Record the event with default values.
     *
     * @param array $payload
     */
    protected function record($payload)
    {
        $this->client->breadcrumbs->record(array_merge([
            'data'  => null,
            'level' => 'info',
        ], $payload));
    }

    /**
     * Until Laravel 5.1
     *
     * @param Route $route
     */
    protected function routerMatchedHandler(Route $route)
    {
        $routeName = $route->getActionName();

        if ($routeName && $routeName !== 'Closure') {
            $this->client->transaction->push($routeName);
        }
    }

    /**
     * Since Laravel 5.2
     *
     * @param RouteMatched $match
     */
    protected function routeMatchedHandler(RouteMatched $match)
    {
        $this->routerMatchedHandler($match->route);
    }

    /**
     * Until Laravel 5.1
     *
     * @param $query
     * @param $bindings
     * @param $time
     * @param $connectionName
     */
    protected function queryHandler($query, $bindings, $time, $connectionName)
    {
        $data = [
            'connectionName' => $connectionName,
        ];

        if ($this->sqlBindings && !empty($bindings)) {
            $data['bindings'] = $bindings;
        }

        $this->record([
            'message'  => $query,
            'category' => 'sql.query',
            'data'     => $data
        ]);
    }

    /**
     * Since Laravel 5.2
     *
     * @param QueryExecuted $query
     */
    protected function queryExecutedHandler(QueryExecuted $query)
    {
        $data = [
            'connectionName' => $query->connectionName,
        ];

        if ($this->sqlBindings && !empty($query->bindings)) {
            $data['bindings'] = $query->bindings;
        }

        $this->client->breadcrumbs->record([
            'message'  => $query->sql,
            'category' => 'sql.query',
            'data'     => $data,
        ]);
    }

    /**
     * Until Laravel 5.3
     *
     * @param $level
     * @param $message
     * @param $context
     */
    protected function logHandler($level, $message, $context)
    {
        $this->client->breadcrumbs->record([
            'message'  => $message,
            'category' => 'log.' . $level,
            'data'     => empty($context) ? null : ['params' => $context],
            'level'    => $level,
        ]);
    }

    /**
     * Since Laravel 5.4
     *
     * @param MessageLogged $logEntry
     */
    protected function messageLoggedHandler(MessageLogged $logEntry)
    {
        $this->client->breadcrumbs->record([
            'message'  => $logEntry->message,
            'category' => 'log.' . $logEntry->level,
            'data'     => empty($logEntry->context) ? null : ['params' => $logEntry->context],
            'level'    => $logEntry->level,
        ]);
    }
}
