<?php

namespace Sentry\Laravel\Features;

use Livewire\Component;
use Livewire\EventBus;
use Livewire\LivewireManager;
use Livewire\Request;
use Sentry\Breadcrumb;
use Sentry\Laravel\Integration;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\TransactionSource;

class LivewirePackageIntegration extends Feature
{
    private const FEATURE_KEY = 'livewire';

    /** @var array<Span> */
    private $spanStack = [];

    public function isApplicable(): bool
    {
        if (!class_exists(LivewireManager::class)) {
            return false;
        }

        return $this->isTracingFeatureEnabled(self::FEATURE_KEY)
            || $this->isBreadcrumbFeatureEnabled(self::FEATURE_KEY);
    }

    public function onBoot(LivewireManager $livewireManager): void
    {
        if (class_exists(EventBus::class)) {
            $this->registerLivewireThreeEventListeners($livewireManager);

            return;
        }

        $this->registerLivewireTwoEventListeners($livewireManager);
    }

    private function registerLivewireThreeEventListeners(LivewireManager $livewireManager): void
    {
        $livewireManager->listen('mount', function ($component, array $data) {
            if ($this->isTracingFeatureEnabled(self::FEATURE_KEY)) {
                $this->handleComponentBoot($component);
            }

            if ($this->isBreadcrumbFeatureEnabled(self::FEATURE_KEY)) {
                $this->handleComponentMount($component, $data);
            }
        });

        $livewireManager->listen('hydrate', function ($component, array $data) {
            if ($this->isTracingFeatureEnabled(self::FEATURE_KEY)) {
                $this->handleComponentBoot($component);
            }

            if ($this->isBreadcrumbFeatureEnabled(self::FEATURE_KEY)) {
                $this->handleComponentHydrate($component, $data);
            }
        });

        if ($this->isTracingFeatureEnabled(self::FEATURE_KEY)) {
            $livewireManager->listen('dehydrate', [$this, 'handleComponentDehydrate']);
        }

        if ($this->isBreadcrumbFeatureEnabled(self::FEATURE_KEY)) {
            $livewireManager->listen('call', [$this, 'handleComponentCall']);
        }
    }

    private function registerLivewireTwoEventListeners(LivewireManager $livewireManager): void
    {
        $livewireManager->listen('component.booted', [$this, 'handleComponentBooted']);

        if ($this->isTracingFeatureEnabled(self::FEATURE_KEY)) {
            $livewireManager->listen('component.boot', function ($component) {
                $this->handleComponentBoot($component);
            });

            $livewireManager->listen('component.dehydrate', [$this, 'handleComponentDehydrate']);
        }

        if ($this->isBreadcrumbFeatureEnabled(self::FEATURE_KEY)) {
            $livewireManager->listen('component.mount', [$this, 'handleComponentMount']);
        }
    }

    public function handleComponentCall(Component $component, string $method, array $data): void
    {
        Integration::addBreadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_DEFAULT,
            'livewire',
            "Component call: {$component->getName()}::{$method}",
            $data
        ));
    }

    public function handleComponentBoot(Component $component, ?string $method = null): void
    {
        if ($this->isLivewireRequest()) {
            $this->updateTransactionName($component->getName());
        }

        $currentSpan = SentrySdk::getCurrentHub()->getSpan();

        if ($currentSpan === null) {
            return;
        }

        $this->spanStack[] = $currentSpan;

        $context = new SpanContext;
        $context->setOp('ui.livewire.component');
        $context->setDescription(
            empty($method)
                ? $component->getName()
                : "{$component->getName()}::{$method}"
        );

        $componentSpan = $currentSpan->startChild($context);

        SentrySdk::getCurrentHub()->setSpan($componentSpan);
    }

    public function handleComponentMount(Component $component, array $data): void
    {
        Integration::addBreadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_DEFAULT,
            'livewire',
            "Component mount: {$component->getName()}",
            $data
        ));
    }

    public function handleComponentBooted(Component $component, Request $request): void
    {
        if (!$this->isLivewireRequest()) {
            return;
        }

        if ($this->isBreadcrumbFeatureEnabled(self::FEATURE_KEY)) {
            Integration::addBreadcrumb(new Breadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_DEFAULT,
                'livewire',
                "Component booted: {$component->getName()}",
                ['updates' => $request->updates]
            ));
        }

        if ($this->isTracingFeatureEnabled(self::FEATURE_KEY)) {
            $this->updateTransactionName($component->getName());
        }
    }

    public function handleComponentHydrate(Component $component, array $data): void
    {
        Integration::addBreadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_DEFAULT,
            'livewire',
            "Component hydrate: {$component->getName()}",
            $data
        ));
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
            $request = $this->container()->make('request');

            if ($request === null) {
                return false;
            }

            return $request->hasHeader('x-livewire');
        } catch (\Throwable $e) {
            // If the request cannot be resolved, it's probably not a Livewire request.
            return false;
        }
    }
}
