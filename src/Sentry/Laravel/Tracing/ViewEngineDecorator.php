<?php


namespace Sentry\Laravel\Tracing;

use Illuminate\Contracts\View\Engine;
use Illuminate\View\Compilers\CompilerInterface;
use Illuminate\View\Factory;
use Sentry\Laravel\Integration;
use Sentry\Tracing\SpanContext;

final class ViewEngineDecorator implements Engine
{
    public const SHARED_KEY = '__sentry_tracing_view_name';

    /** @var Engine */
    private $engine;

    /** @var Factory */
    private $viewFactory;

    public function __construct(Engine $engine, Factory $viewFactory)
    {
        $this->engine = $engine;
        $this->viewFactory = $viewFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function get($path, array $data = []): string
    {
        $parentSpan = Integration::currentTracingSpan();

        if ($parentSpan === null) {
            return $this->engine->get($path, $data);
        }

        $context = new SpanContext();
        $context->setOp('view.render');
        $context->setDescription($this->viewFactory->shared(self::SHARED_KEY, basename($path)));

        $span = $parentSpan->startChild($context);

        $result = $this->engine->get($path, $data);

        $span->finish();

        return $result;
    }

    /**
     * Laravel uses this function internally
     */
    public function getCompiler(): CompilerInterface
    {
        return $this->engine->getCompiler();
    }
}
