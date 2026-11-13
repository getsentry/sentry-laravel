<?php

namespace Sentry\Laravel\Tests\Features;

use Illuminate\Contracts\View\View;
use Illuminate\View\Engines\EngineResolver;
use ReflectionProperty;
use Sentry\Laravel\Tests\TestCase;
use Sentry\Laravel\Tracing\ServiceProvider;
use Sentry\Laravel\Tracing\ViewEngineDecorator;

class ViewEngineDecoratorTest extends TestCase
{
    private const STUBS_PATH = __DIR__ . '/../../stubs';

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

    public function testViewComposerIsRegisteredOnlyOnceWhenEnginesAreRecreated(): void
    {
        /** @var EngineResolver $engineResolver */
        $engineResolver = $this->app->make('view.engine.resolver');

        $engineResolver->resolve('blade');

        $before = $this->countWildcardComposers();

        for ($i = 0; $i < 10; $i++) {
            $engineResolver->forget('blade');
            $engineResolver->resolve('blade');
        }

        $this->assertSame($before, $this->countWildcardComposers());
    }

    private function countWildcardComposers(): int
    {
        $events = $this->app->make('view')->getDispatcher();

        $property = new ReflectionProperty($events, 'wildcards');

        if (\PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }

        return count($property->getValue($events)['composing: *'] ?? []);
    }

    public function testNamedViewUsesViewNameAsSpanDescription(): void
    {
        $this->app->make('view')->addLocation(self::STUBS_PATH . '/views');

        $view = $this->app->make('view')->make('hello');

        $this->assertSame('hello', $this->renderAndGetSpanDescription($view));
    }

    public function testFileViewInsideBasePathHasBasePathStrippedFromSpanDescription(): void
    {
        // Use the stubs directory as the application base path so the view file is located inside of it
        $this->app->setBasePath(self::STUBS_PATH);

        $view = $this->app->make('view')->file(self::STUBS_PATH . '/views/hello.blade.php');

        $this->assertSame('/views/hello.blade.php', $this->renderAndGetSpanDescription($view));
    }

    public function testFileViewOutsideBasePathKeepsFullPathAsSpanDescription(): void
    {
        $view = $this->app->make('view')->file(self::STUBS_PATH . '/views/hello.blade.php');

        $this->assertSame(self::STUBS_PATH . '/views/hello.blade.php', $this->renderAndGetSpanDescription($view));
    }

    private function renderAndGetSpanDescription(View $view): ?string
    {
        $transaction = $this->startTransaction();

        $view->render();

        // The first recorded span is the transaction itself, the second is the span for the rendered view
        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame('view.render', $spans[1]->getOp());

        return $spans[1]->getDescription();
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
