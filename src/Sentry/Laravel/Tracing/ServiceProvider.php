<?php

namespace Sentry\Laravel\Tracing;

use Illuminate\Contracts\Http\Kernel as HttpKernelInterface;
use Illuminate\Contracts\View\Engine;
use Illuminate\Contracts\View\View;
use Illuminate\Support\ServiceProvider as IlluminateServiceProvider;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory as ViewFactory;

class ServiceProvider extends IlluminateServiceProvider
{
    public function boot(): void
    {
        if ($this->app->bound(HttpKernelInterface::class)) {
            /** @var \Illuminate\Contracts\Http\Kernel $httpKernel */
            $httpKernel = $this->app->make(HttpKernelInterface::class);

            $httpKernel->prependMiddleware(Middleware::class);
        }
    }

    public function register(): void
    {
        $this->app->singleton(Middleware::class);

        $this->app->afterResolving('view.engine.resolver', function (EngineResolver $engineResolver): void {
            foreach (['file', 'php', 'blade'] as $engineName) {
                try {
                    $realEngine = $engineResolver->resolve($engineName);

                    $engineResolver->register($engineName, function () use ($realEngine) {
                        return $this->wrapViewEngine($realEngine);
                    });
                } catch (InvalidArgumentException $e) {
                    // The `file` engine was introduced in Laravel 5.4 and will throw an `InvalidArgumentException` on Laravel 5.3 and below
                }
            }
        });
    }

    private function wrapViewEngine(Engine $realEngine): Engine
    {
        /** @var ViewFactory $viewFactory */
        $viewFactory = $this->app->make('view');

        /** @noinspection UnusedFunctionResultInspection */
        $viewFactory->composer('*', static function (View $view) use ($viewFactory) : void {
            $viewFactory->share(ViewEngineDecorator::SHARED_KEY, $view->name());
        });

        return new ViewEngineDecorator($realEngine, $viewFactory);
    }
}
