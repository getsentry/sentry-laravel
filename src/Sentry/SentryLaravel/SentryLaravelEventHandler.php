<?php

namespace Sentry\SentryLaravel;

use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Log\Writer;

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
        $this->logMessages = (
            isset($config['log_messages'])
            ? $config['log_messages']
            : false
        );
    }

    public function subscribe(Dispatcher $events)
    {
        $this->events = $events;
        $events->listen('*', [$this, 'onWildcardEvent']);
    }

    public function onWildcardEvent()
    {
        $args = func_get_args();
        try {
            $this->_onWildcardEvent($args);
        } catch (\Exception $e) {
        }
    }

    protected function _onWildcardEvent($args)
    {
        $name = $this->events->firing();
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

    public function subscribeLog(Writer $log, Application $app)
    {
        if (!$this->logMessages) {
            return;
        }

        $this->app = $app;
        $log->listen(function () {
            $this->onLogEvent(func_get_args());
        });
    }

    public function onLogEvent($args)
    {
        $context = $this->buildContext($args[0], $args[2]);
        $this->client->captureMessage($args[1], [], $context);
    }

    public function buildContext($level, $options)
    {
        $context = [];
        $context['level'] = $level;

        // Add session data if available.
        if (isset($this->app['session']) && $session = $this->app['session']->all()) {
            if (empty($context['user']) or !is_array($context['user'])) {
                $context['user'] = [];
            }
            if (!isset($context['user']['id'])) {
                $context['user']['id'] = $this->app->session->getId();
            }
            if (isset($context['user']['data'])) {
                $context['user']['data'] = array_merge($session, $context['user']['data']);
            } else {
                $context['user']['data'] = $session;
            }
        }

        $context['tags'] = [
            'environment' => $this->app->environment(),
            'server' => $this->app->request->server('HTTP_HOST'),
            'php_version' => phpversion(),
        ];

        $extra = [
            'ip' => $this->app->request->getClientIp(),
        ];

        $extra = array_merge($extra, array_except($options, ['user', 'tags', 'level', 'extra']));

        if (isset($context['extra'])) {
            $context['extra'] = array_merge($extra, $context['extra']);
        } else {
            $context['extra'] = $extra;
        }

        return array_only($context, ['user', 'tags', 'level', 'extra']);
    }
}
