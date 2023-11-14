<?php

namespace Sentry\Laravel\Features;

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

    private const COMPONENT_SPAN_OP = 'ui.livewire.component';

    /** @var array<Span> */
    private $spanStack = [];

    /** @var null|LivewireManager */
    private $livewireManager = null;

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
        $this->livewireManager = $livewireManager;

        if (class_exists(EventBus::class)) {
            $this->registerLivewireThreeEventListeners($livewireManager);

            return;
        }

        $this->registerLivewireTwoEventListeners($livewireManager);
    }

    public function handleComponentBoot($component, ?string $method = null): void
    {
        if ($this->isTracingFeatureEnabled(self::FEATURE_KEY) && $this->isLivewireRequest()) {
            $this->updateTransactionName($component->getName());
        }

        $currentSpan = SentrySdk::getCurrentHub()->getSpan();

        if ($currentSpan === null) {
            return;
        }

        $this->spanStack[] = $currentSpan;

        if (filled($method)) {
            $method = '::' . $method;
        }

        $context = new SpanContext;
        $context->setOp(self::COMPONENT_SPAN_OP);
        $context->setDescription($component->getName() . $method);

        $componentSpan = $currentSpan->startChild($context);

        SentrySdk::getCurrentHub()->setSpan($componentSpan);
    }

    public function handleComponentMount($component, array $data): void
    {
        Integration::addBreadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_DEFAULT,
            'livewire',
            "Component mount: {$component->getName()}",
            $data
        ));
    }

    public function handleComponentBooted($component, $request): void
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
    }

    public function handleComponentHydrate($component, array $data): void
    {
        Integration::addBreadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_DEFAULT,
            'livewire',
            "Component hydrate: {$component->getName()}",
            $data
        ));
    }

    public function handleComponentDehydrate($component): void
    {
        $currentSpan = SentrySdk::getCurrentHub()->getSpan();

        if ($currentSpan === null || empty($this->spanStack)) {
            return;
        }

        $currentSpan->finish();

        $previousSpan = array_pop($this->spanStack);

        SentrySdk::getCurrentHub()->setSpan($previousSpan);
    }

    public function handleComponentCall($component, string $method, array $data): void
    {
        Integration::addBreadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_DEFAULT,
            'livewire',
            "Component call: {$component->getName()}::{$method}",
            $data
        ));
    }

    private function registerLivewireTwoEventListeners($livewireManager): void
    {
        $livewireManager->listen('component.booted', function ($component, $request) {
            $this->handleComponentBoot($component);
        });

        if ($this->isTracingFeatureEnabled(self::FEATURE_KEY)) {
            $livewireManager->listen('component.boot', [$this, 'handleComponentBoot']);
            $livewireManager->listen('component.dehydrate', [$this, 'handleComponentDehydrate']);
        }

        if ($this->isBreadcrumbFeatureEnabled(self::FEATURE_KEY)) {
            $livewireManager->listen('component.mount', [$this, 'handleComponentMount']);
        }
    }

    private function registerLivewireThreeEventListeners($livewireManager): void
    {
        $livewireManager->listen('mount', function ($component, array $data) {
            $this->handleComponentBoot($component);

            if ($this->isTracingFeatureEnabled(self::FEATURE_KEY)) {
                $this->handleComponentMount($component, $data);
            }
        });

        $livewireManager->listen('hydrate', function ($component, array $data) {
            $this->handleComponentBoot($component);

            if ($this->isTracingFeatureEnabled(self::FEATURE_KEY)) {
                $this->handleComponentHydrate($component, $data);
            }
        });

        if ($this->isTracingFeatureEnabled(self::FEATURE_KEY)) {
            $livewireManager->listen('dehydrate', [$this, 'handleComponentDehydrate']);
        }

        $livewireManager->listen('call', [$this, 'handleComponentCall']);
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
            return $this->livewireManager->isLivewireRequest();
        } catch (\Throwable $e) {
            // If the request cannot be resolved, it's probably not a Livewire request.
            return false;
        }
    }
}
