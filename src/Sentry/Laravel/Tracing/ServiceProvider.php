<?php

namespace Sentry\Laravel\Tracing;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel as HttpKernelInterface;
use Illuminate\Contracts\View\Engine;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Routing\Contracts\CallableDispatcher;
use Illuminate\Routing\Contracts\ControllerDispatcher;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory as ViewFactory;
use InvalidArgumentException;
use Sentry\Laravel\BaseServiceProvider;
use Sentry\Laravel\Tracing\Routing\TracingCallableDispatcherTracing;
use Sentry\Laravel\Tracing\Routing\TracingControllerDispatcherTracing;
use Sentry\Serializer\RepresentationSerializer;

class ServiceProvider extends BaseServiceProvider
{
    public const DEFAULT_INTEGRATIONS = [
        Integrations\LighthouseIntegration::class,
    ];

    public function boot(): void
    {
        // If there is no DSN set we register nothing since it's impossible for us to send traces without a DSN set
        if (!$this->hasDsnSet()) {
            return;
        }

        $this->app->booted(function () {
            $this->app->make(Middleware::class)->setBootedTimestamp();
        });

        $tracingConfig = $this->getUserConfig()['tracing'] ?? [];

        $this->bindEvents($tracingConfig);

        $this->bindViewEngine($tracingConfig);

        $this->decorateRoutingDispatchers();

        if ($this->app->bound(HttpKernelInterface::class)) {
            /** @var \Illuminate\Foundation\Http\Kernel $httpKernel */
            $httpKernel = $this->app->make(HttpKernelInterface::class);

            if ($httpKernel instanceof HttpKernel) {
                $httpKernel->prependMiddleware(Middleware::class);
            }
        }
    }

    public function register(): void
    {
        $this->app->singleton(Middleware::class);

        $this->app->singleton(BacktraceHelper::class, function () {
            /** @var \Sentry\State\Hub $sentry */
            $sentry = $this->app->make(self::$abstract);

            $options = $sentry->getClient()->getOptions();

            return new BacktraceHelper($options, new RepresentationSerializer($options));
        });
    }

    private function bindEvents(array $tracingConfig): void
    {
        $handler = new EventHandler(
            $tracingConfig,
            $this->app->make(BacktraceHelper::class)
        );

        try {
            /** @var \Illuminate\Contracts\Events\Dispatcher $dispatcher */
            $dispatcher = $this->app->make(Dispatcher::class);

            $handler->subscribe($dispatcher);

            if ($this->app->bound('queue')) {
                $handler->subscribeQueueEvents($dispatcher, $this->app->make('queue'));
            }
        } catch (BindingResolutionException $e) {
            // If we cannot resolve the event dispatcher we also cannot listen to events
        }
    }

    private function bindViewEngine($tracingConfig): void
    {
        if (($tracingConfig['views'] ?? true) !== true) {
            return;
        }

        $viewEngineWrapper = function (EngineResolver $engineResolver): void {
            foreach (['file', 'php', 'blade'] as $engineName) {
                try {
                    $realEngine = $engineResolver->resolve($engineName);

                    $engineResolver->register($engineName, function () use ($realEngine) {
                        return $this->wrapViewEngine($realEngine);
                    });
                } catch (InvalidArgumentException $e) {
                    // The `file` engine was introduced in Laravel 5.4. On lower Laravel versions
                    // resolving that driver  will throw an `InvalidArgumentException`. We can
                    // ignore this exception because we can't wrap drivers that don't exist
                }
            }
        };

        if ($this->app->resolved('view.engine.resolver')) {
            $viewEngineWrapper($this->app->make('view.engine.resolver'));
        } else {
            $this->app->afterResolving('view.engine.resolver', $viewEngineWrapper);
        }
    }

    private function wrapViewEngine(Engine $realEngine): Engine
    {
        /** @var ViewFactory $viewFactory */
        $viewFactory = $this->app->make('view');

        $viewFactory->composer('*', static function (View $view) use ($viewFactory): void {
            $viewFactory->share(ViewEngineDecorator::SHARED_KEY, $view->name());
        });

        return new ViewEngineDecorator($realEngine, $viewFactory);
    }

    private function decorateRoutingDispatchers(): void
    {
        $this->app->extend(CallableDispatcher::class, static function (CallableDispatcher $dispatcher) {
            return new TracingCallableDispatcherTracing($dispatcher);
        });

        $this->app->extend(ControllerDispatcher::class, static function (ControllerDispatcher $dispatcher) {
            return new TracingControllerDispatcherTracing($dispatcher);
        });
    }
}
