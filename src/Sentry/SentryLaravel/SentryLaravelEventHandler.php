<?php

namespace Sentry\SentryLaravel;

use Illuminate\Events\Dispatcher;

// Event handling inspired by the ``laravel-debugbar`` project:
//   https://github.com/barryvdh/laravel-debugbar
class SentryLaravelEventHandler
{
    public function __construct(\Raven_Client $client, array $config)
    {
        $this->client = $client;
        $this->sqlBindings = (
            isset($config['breadcrumbs.sql_bindings'])
            ? $config['breadcrumbs.sql_bindings']
            : true
        );
    }

    public function subscribe(Dispatcher $events)
    {
        $this->events = $events;
        $events->listen('*', [$this, 'onWildcardEvent']);
    }

    public function onWildcardEvent()
    {
        $name = $this->events->firing();
        $args = func_get_args();
        $data = null;
        $level = 'info';
        if ($name === 'Illuminate\Routing\Events\RouteMatched') {
            $route = $args[0]->route;
            $routeName = $route->getActionName();
            if ($routeName && $routeName !== 'Closure') {
                $this->client->transaction->push($routeName);
            }
        } elseif ($name === 'router.matched') {
            $route = $args[0];
            $routeName = $route->getActionName();
            if ($routeName && $routeName !== 'Closure') {
                $this->client->transaction->push($routeName);
            }
        }

        if ($name === 'Illuminate\Database\Events\QueryExecuted') {
            $name = 'sql.query';
            $message = $args[0]->sql;
            $data = array(
                'connectionName' => $args[0]->connectionName,
            );
            if ($this->sqlBindings) {
                $bindings = $args[0]->bindings;
                if (!empty($bindings)) {
                    $data['bindings'] = $bindings;
                }
            }
        } elseif ($name === 'illuminate.query') {
            // $args = array(sql, bindings, ...)
            $name = 'sql.query';
            $message = $args[0];
            $data = array(
                'connectionName' => $args[3],
            );
            if ($this->sqlBindings) {
                $bindings = $args[1];
                if (!empty($bindings)) {
                    $data['bindings'] = $bindings;
                }
            }
        } elseif ($name === 'illuminate.log') {
            $name = 'log.' . $args[0];
            $level = $args[0];
            $message = $args[1];
            if (!empty($args[2])) {
                $data = array('params' => $args[2]);
            }
        } else {
            return;
        }
        $this->client->breadcrumbs->record(array(
            'message' => $message,
            'category' => $name,
            'data' => $data,
            'level' => $level,
        ));
    }
}
