<?php


namespace Sentry\Laravel;

use Illuminate\Contracts\View\Engine;
use Illuminate\View\Compilers\CompilerInterface;
use Illuminate\View\Factory;
use Sentry\State\Scope;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\Span;

final class TracingViewEngineDecorator implements Engine
{
    public const SHARED_KEY = '__sentry_tracing_view_name';

    /** @var Engine */
    private $engine;

    /** @var Factory */
    private $viewFactory;

    /** @var Span */
    private $parentSpan;

    public function __construct(Engine $engine, Factory $viewFactory, Span $parentSpan)
    {
        $this->engine = $engine;
        $this->viewFactory = $viewFactory;
        $this->parentSpan = $parentSpan;
    }

    /**
     * {@inheritdoc}
     */
    public function get($path, array $data = []): string
    {
        $context = new SpanContext();
        $context->op = 'render';
        $context->description = $this->viewFactory->shared(self::SHARED_KEY, basename($path));

        $span = $this->parentSpan->startChild($context);
        
        if ($this->parentSpan->getStartTimestamp() < 0) {
            $this->parentSpan->setStartTimestamp($span->getStartTimestamp());
        }

        $result = $this->engine->get($path, $data);

        $span->finish();

        if ($end = $span->getEndTimestamp()) {
            $this->parentSpan->finish($end);
        }

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
