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
    private $realEngine;

    /** @var Factory */
    private $viewFactory;

    public function __construct(Engine $realEngine, Factory $viewFactory)
    {
        $this->realEngine = $realEngine;
        $this->viewFactory = $viewFactory;
    }

    /**
     * {@inheritDoc}
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

        $result = $this->realEngine->get($path, $data);

        if (null !== $span) {
            $span->finish();
        }

        return $result;
    }

    /**
     * Since Laravel has a nasty habit of exposing public API that is not defined in interfaces, we must expose the
     * getCompiler method commonly used in the actual view engines.
     *
     * Unfortunately, we have to disable all kinds of static analysis due to this violation :/
     *
     * @noinspection PhpUnused
     */
    public function getCompiler(): CompilerInterface
    {
        /**
         * @noinspection PhpUndefinedMethodInspection
         * @psalm-suppress UndefinedInterfaceMethod
         */
        return $this->realEngine->getCompiler();
    }
}