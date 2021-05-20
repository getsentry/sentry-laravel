<?php

namespace Sentry\Laravel\Tracing;

use Illuminate\Contracts\Http\Kernel as HttpKernelInterface;
use Illuminate\Contracts\View\Engine;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Queue\QueueManager;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory as ViewFactory;
use InvalidArgumentException;
use Laravel\Lumen\Application as Lumen;
use Sentry\Laravel\BaseServiceProvider;
use Sentry\Serializer\RepresentationSerializer;

class ServiceProvider extends BaseServiceProvider
{
    public const DEFAULT_INTEGRATIONS = [
        Integrations\LighthouseIntegration::class,
    ];

    public function boot(): void
    {
        if ($this->hasDsnSet() && $this->couldHavePerformanceTracingEnabled()) {
            $tracingConfig = $this->getUserConfig()['tracing'] ?? [];

            $this->bindEvents($tracingConfig);

            $this->bindViewEngine($tracingConfig);

            if ($this->app instanceof Lumen) {
                $this->app->middleware(Middleware::class);
            } elseif ($this->app->bound(HttpKernelInterface::class)) {
                /** @var \Illuminate\Foundation\Http\Kernel $httpKernel */
                $httpKernel = $this->app->make(HttpKernelInterface::class);

                if ($httpKernel instanceof HttpKernel) {
                    $httpKernel->prependMiddleware(Middleware::class);
                }
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

        if (!$this->app instanceof Lumen) {
            $this->app->booted(function () {
                $this->app->make(Middleware::class)->setBootedTimestamp();
            });
        }
    }

    private function bindEvents(array $tracingConfig): void
    {
        $handler = new EventHandler(
            $this->app,
            $this->app->make(BacktraceHelper::class),
            $tracingConfig
        );

        $handler->subscribe();

        if ($this->app->bound(QueueManager::class)) {
            $handler->subscribeQueueEvents(
                $this->app->make(QueueManager::class)
            );
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
}
