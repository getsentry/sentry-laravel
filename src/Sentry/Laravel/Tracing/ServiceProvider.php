<?php

namespace Sentry\Laravel\Tracing;

use Illuminate\Contracts\Http\Kernel as HttpKernelInterface;
use Illuminate\Contracts\View\Engine;
use Illuminate\Contracts\View\View;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory as ViewFactory;
use Sentry\Laravel\BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function boot(): void
    {
        if ($this->hasDsnSet()) {
            $this->bindEvents($this->app);

            $this->bindViewEngine();

            if ($this->app->bound(HttpKernelInterface::class)) {
                /** @var \Illuminate\Contracts\Http\Kernel $httpKernel */
                $httpKernel = $this->app->make(HttpKernelInterface::class);

                $httpKernel->prependMiddleware(Middleware::class);
            }
        }
    }

    public function register(): void
    {
        $this->app->singleton(Middleware::class);
    }

    private function bindEvents(): void
    {
        $handler = new EventHandler($this->app->events);

        $handler->subscribe();
    }

    private function bindViewEngine(): void
    {
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

        $viewFactory->composer('*', static function (View $view) use ($viewFactory) : void {
            $viewFactory->share(ViewEngineDecorator::SHARED_KEY, $view->name());
        });

        return new ViewEngineDecorator($realEngine, $viewFactory);
    }
}
