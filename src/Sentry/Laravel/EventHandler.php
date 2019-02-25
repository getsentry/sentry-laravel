<?php

namespace Sentry\Laravel;

use Exception;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Routing\Route;
use RuntimeException;
use Sentry\State\Scope;
use Sentry\Breadcrumb;
use Sentry\State\Hub;

class EventHandler
{
    /**
     * Maps event handler function to event names.
     *
     * @var array
     */
    protected static $eventHandlerMap = array(
        'router.matched' => 'routerMatched', // Until Laravel 5.1
        'Illuminate\Routing\Events\RouteMatched' => 'routeMatched',  // Since Laravel 5.2

        'illuminate.query' => 'query',         // Until Laravel 5.1
        'Illuminate\Database\Events\QueryExecuted' => 'queryExecuted', // Since Laravel 5.2

        'illuminate.log' => 'log',           // Until Laravel 5.3
        'Illuminate\Log\Events\MessageLogged' => 'messageLogged', // Since Laravel 5.4

        'Illuminate\Queue\Events\JobProcessed' => 'queueJobProcessed', // since Laravel 5.2
        'Illuminate\Queue\Events\JobProcessing' => 'queueJobProcessing', // since Laravel 5.2

        'Illuminate\Console\Events\CommandStarting' => 'commandStarting', // Since Laravel 5.5
        'Illuminate\Console\Events\CommandFinished' => 'commandFinished', // Since Laravel 5.5
    );

    /**
     * Maps authentication event handler function to event names.
     *
     * @var array
     */
    protected static $authEventHandlerMap = array(
        'Illuminate\Auth\Events\Authenticated' => 'authenticated', // Since Laravel 5.3
    );

    /**
     * Indicates if we should we add query bindings to the breadcrumbs.
     *
     * @var bool
     */
    private $sqlBindings;

    /**
     * EventHandler constructor.
     *
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->sqlBindings = isset($config['breadcrumbs.sql_bindings']) ? $config['breadcrumbs.sql_bindings'] === true : true;
    }

    /**
     * Attach all event handlers.
     *
     * @param \Illuminate\Contracts\Events\Dispatcher $events
     */
    public function subscribe(Dispatcher $events)
    {
        foreach (static::$eventHandlerMap as $eventName => $handler) {
            $events->listen($eventName, array($this, $handler));
        }
    }

    /**
     * Attach all authentication event handlers.
     *
     * @param \Illuminate\Contracts\Events\Dispatcher $events
     */
    public function subscribeAuthEvents(Dispatcher $events)
    {
        foreach (static::$authEventHandlerMap as $eventName => $handler) {
            $events->listen($eventName, array($this, $handler));
        }
    }

    /**
     * Pass through the event and capture any errors.
     *
     * @param string $method
     * @param array  $arguments
     */
    public function __call($method, $arguments)
    {
        if (!method_exists($this, $method . 'handler')) {
            throw new RuntimeException('Missing event handler:' . $method . 'handler');
        }

        try {
            call_user_func_array(array($this, $method . 'handler'), $arguments);
        } catch (Exception $exception) {
            // Ignore
        }
    }

    /**
     * Until Laravel 5.1
     *
     * @param Route $route
     */
    protected function routerMatchedHandler(Route $route)
    {
        if ($route->getName()) {
            // someaction (route name/alias)
            $routeName = $route->getName();
        } elseif ($route->getActionName()) {
            // SomeController@someAction (controller action)
            $routeName = $route->getActionName();
        }
        if (empty($routeName) || $routeName === 'Closure') {
            // /someaction // Fallback to the url
            $routeName = $route->uri();
        }

        Integration::addBreadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_NAVIGATION,
            'route',
            $routeName
        ));
        Integration::setTransaction($routeName);
    }

    /**
     * Since Laravel 5.2
     *
     * @param \Illuminate\Routing\Events\RouteMatched $match
     */
    protected function routeMatchedHandler(RouteMatched $match)
    {
        $this->routerMatchedHandler($match->route);
    }

    /**
     * Until Laravel 5.1
     *
     * @param string $query
     * @param array  $bindings
     * @param int    $time
     * @param string $connectionName
     */
    protected function queryHandler($query, $bindings, $time, $connectionName)
    {
        $data = array('connectionName' => $connectionName);

        if ($this->sqlBindings) {
            $data['bindings'] = $bindings;
        }

        Integration::addBreadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_USER,
            'sql.query',
            $query,
            $data
        ));
    }

    /**
     * Since Laravel 5.2
     *
     * @param \Illuminate\Database\Events\QueryExecuted $query
     */
    protected function queryExecutedHandler(QueryExecuted $query)
    {
        $data = array('connectionName' => $query->connectionName);

        if ($this->sqlBindings) {
            $data['bindings'] = $query->bindings;
        }

        Integration::addBreadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_USER,
            'sql.query',
            $query->sql,
            $data
        ));
    }

    /**
     * Until Laravel 5.3
     *
     * @param string     $level
     * @param string     $message
     * @param array|null $context
     */
    protected function logHandler($level, $message, $context)
    {
        Integration::addBreadcrumb(new Breadcrumb(
            $level,
            Breadcrumb::TYPE_USER,
            'log.' . $level,
            $message,
            empty($context) ? array() : array('params' => $context)
        ));
    }

    /**
     * Since Laravel 5.4
     *
     * @param \Illuminate\Log\Events\MessageLogged $logEntry
     */
    protected function messageLoggedHandler(MessageLogged $logEntry)
    {
        Integration::addBreadcrumb(new Breadcrumb(
            $logEntry->level,
            Breadcrumb::TYPE_USER,
            'log.' . $logEntry->level,
            $logEntry->message,
            empty($logEntry->context) ? array() : array('params' => $logEntry->context)
        ));
    }

    /**
     * Since Laravel 5.3
     *
     * @param \Illuminate\Auth\Events\Authenticated $event
     */
    protected function authenticatedHandler(Authenticated $event)
    {
        Integration::configureScope(function (Scope $scope) use ($event): void {
            $scope->setUser(array(
                'id' => $event->user->getAuthIdentifier(),
            ));
        });
    }

    /**
     * Since Laravel 5.2
     *
     * @param \Illuminate\Queue\Events\JobProcessing $event
     */
    protected function queueJobProcessingHandler(JobProcessing $event)
    {
        // When a job starts, we want to push a new scope
        Hub::getCurrent()->pushScope();

        $job = [
            'job' => $event->job->getName(),
            'queue' => $event->job->getQueue(),
            'attempts' => $event->job->attempts(),
            'connection' => $event->connectionName,
        ];

        // Resolve name exists only from Laravel 5.3+
        if (method_exists($event->job, 'resolveName')) {
            $job['resolved'] = $event->job->resolveName();
        }

        Integration::addBreadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_USER,
            'queue.job',
            'Processing queue job',
            $job
        ));
    }

    /**
     * Since Laravel 5.2
     *
     * @param \Illuminate\Queue\Events\JobProcessed $event
     */
    protected function queueJobProcessedHandler(JobProcessed $event)
    {
        // When a job finished, we want to pop the scope
        Hub::getCurrent()->popScope();
    }

    /**
     * Since Laravel 5.5
     *
     * @param \Illuminate\Console\Events\CommandStarting $event
     */
    protected function commandStartingHandler(CommandStarting $event)
    {
        Integration::configureScope(function (Scope $scope) use ($event): void {
            if ($event->command) {
                $scope->setTag('command', $event->command);
            }
        });
    }

    /**
     * Since Laravel 5.5
     *
     * @param \Illuminate\Console\Events\CommandFinished $event
     */
    protected function commandFinishedHandler(CommandFinished $event)
    {
        Integration::configureScope(function (Scope $scope) use ($event): void {
            $scope->setTag('command', '');
        });
    }
}
