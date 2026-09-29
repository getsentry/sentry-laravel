<?php

namespace Sentry\Laravel\Tests\Features;

use Illuminate\View\Engines\EngineResolver;
use ReflectionProperty;
use Sentry\Laravel\Tests\TestCase;
use Sentry\Laravel\Tracing\ServiceProvider;
use Sentry\Laravel\Tracing\ViewEngineDecorator;

class ViewEngineDecoratorTest extends TestCase
{
    public function testViewEngineIsDecorated(): void
    {
        /** @var EngineResolver $engineResolver */
        $engineResolver = $this->app->make('view.engine.resolver');

        foreach (['file', 'php', 'blade'] as $engineName) {
            $engine = $engineResolver->resolve($engineName);

            $this->assertInstanceOf(ViewEngineDecorator::class, $engine, "Engine `{$engineName}` should be wrapped in a ViewEngineDecorator.");
        }
    }

    public function testViewEngineIsNotDoubleDecorated(): void
    {
        // Boot the tracing service provider again to simulate the wrapping running a second time
        (new ServiceProvider($this->app))->boot();

        /** @var EngineResolver $engineResolver */
        $engineResolver = $this->app->make('view.engine.resolver');

        foreach (['file', 'php', 'blade'] as $engineName) {
            $engine = $engineResolver->resolve($engineName);

            $this->assertInstanceOf(ViewEngineDecorator::class, $engine, "Engine `{$engineName}` should be wrapped in a ViewEngineDecorator.");

            $innerEngine = $this->getInnerEngine($engine);

            $this->assertNotInstanceOf(ViewEngineDecorator::class, $innerEngine, "Engine `{$engineName}` should not be double wrapped in a ViewEngineDecorator.");
        }
    }

    private function getInnerEngine(ViewEngineDecorator $decorator): object
    {
        $property = new ReflectionProperty(ViewEngineDecorator::class, 'engine');

        if (\PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }

        return $property->getValue($decorator);
    }
}
