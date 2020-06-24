<?php


namespace Sentry\Laravel;

use Illuminate\Contracts\View\Engine;
use Illuminate\View\Compilers\CompilerInterface;
use Illuminate\View\Factory;
use Sentry\SentrySdk;
use Sentry\State\Scope;
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
        $transaction = null;
        $span = null;
        /** @var \Sentry\State\Hub $hub */
        $hub = SentrySdk::getCurrentHub();
        $hub->configureScope(function (Scope $scope) use (&$transaction): void {
            $transaction = $scope->getSpan();
        });

        if (null !== $transaction) {
            $context = new SpanContext();
            $context->op = 'render';
            $context->description = basename($path);
            $span = $transaction->startChild($context);
            $this->viewFactory->shared(self::SHARED_KEY, 'unknown');
        }

        $result = $this->engine->get($path, $data);

        if (null !== $span) {
            $span->finish();
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