<?php

namespace Sentry\Laravel\Tracing\Integrations;

use Illuminate\Contracts\Foundation\Application;
use Livewire\Component;
use Livewire\LivewireManager;
use Livewire\Request;
use Sentry\Integration\IntegrationInterface;
use Sentry\Laravel\Integration;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\TransactionSource;

class LivewireIntegration implements IntegrationInterface
{
    private const COMPONENT_SPAN_OP = 'ui.livewire.component';

    /** @var array<\Sentry\Tracing\Span> */
    private $spanStack = [];

    /** @var \Illuminate\Contracts\Foundation\Application */
    private $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function setupOnce(): void
    {
        if ($this->isApplicable()) {
            try {
                $livewireManager = $this->app->make(LivewireManager::class);
            } catch (\Throwable $e) {
                // If the LivewireManager cannot be resolved, we can't do anything.
                return;
            }

            $livewireManager->listen('component.boot', [$this, 'handleComponentBoot']);
            $livewireManager->listen('component.booted', [$this, 'handleComponentBooted']);
            $livewireManager->listen('component.dehydrate', [$this, 'handleComponentDehydrate']);
        }
    }

    public function handleComponentBoot(Component $component): void
    {
        $currentSpan = SentrySdk::getCurrentHub()->getSpan();

        if ($currentSpan === null) {
            return;
        }

        $this->spanStack[] = $currentSpan;

        $context = new SpanContext;
        $context->setOp(self::COMPONENT_SPAN_OP);
        $context->setDescription($component->getName());

        $componentSpan = $currentSpan->startChild($context);

        SentrySdk::getCurrentHub()->setSpan($componentSpan);
    }

    public function handleComponentDehydrate(Component $component): void
    {
        $currentSpan = SentrySdk::getCurrentHub()->getSpan();

        if ($currentSpan === null || empty($this->spanStack)) {
            return;
        }

        $currentSpan->finish();

        $previousSpan = array_pop($this->spanStack);

        SentrySdk::getCurrentHub()->setSpan($previousSpan);
    }

    public function handleComponentBooted(Component $component, Request $request): void
    {
        if ($this->isLivewireRequest()) {
            $this->updateTransactionName($component::getName());
        }
    }

    private function updateTransactionName(string $componentName): void
    {
        $transaction = SentrySdk::getCurrentHub()->getTransaction();

        if ($transaction === null) {
            return;
        }

        $transactionName = "livewire?component={$componentName}";

        $transaction->setName($transactionName);
        $transaction->getMetadata()->setSource(TransactionSource::custom());

        Integration::setTransaction($transactionName);
    }

    private function isLivewireRequest(): bool
    {
        try {
            /** @var \Illuminate\Http\Request $request */
            $request = $this->app->make('request');

            if ($request === null) {
                return false;
            }

            return $request->header('x-livewire') === 'true';
        } catch (\Throwable $e) {
            // If the request cannot be resolved, it's probably not a Livewire request.
            return false;
        }
    }

    private function isApplicable(): bool
    {
        return class_exists(LivewireManager::class);
    }
}
