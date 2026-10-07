<?php

namespace Sentry\Laravel\Features;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Laravel\Ai\Events\Classified;
use Laravel\Ai\Events\Classifying;
use Laravel\Ai\Events\ProviderFailedOver;
use Sentry\Laravel\Features\Ai\AiProviderUrlResolver;
use Sentry\Laravel\Features\Ai\AiSpanDataBag;
use Sentry\Laravel\Features\Classification\ClassificationInvocationData;
use Sentry\Laravel\Features\Classification\ClassificationMessageFormatter;
use Sentry\Laravel\Util\BoundedOrderedMap;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;

/**
 * @internal
 */
class ClassificationIntegration extends Feature
{
    private const FEATURE_KEY = 'gen_ai';
    private const FEATURE_KEY_EVALUATE = 'gen_ai_evaluate';

    private const MAX_TRACKED_CLASSIFICATIONS = 100;

    /** @var BoundedOrderedMap<ClassificationInvocationData> */
    private $classifications;

    public function __construct(Container $container)
    {
        parent::__construct($container);

        $this->classifications = new BoundedOrderedMap(self::MAX_TRACKED_CLASSIFICATIONS, function (ClassificationInvocationData $classification): void {
            $classification->finishSpan(SpanStatus::deadlineExceeded());
        });
    }

    public function isApplicable(): bool
    {
        // Classification was added in laravel/ai 1.0
        return $this->isTracingFeatureEnabled(self::FEATURE_KEY)
            && class_exists(Classifying::class);
    }

    public function onBoot(Dispatcher $events): void
    {
        $events->listen(Classifying::class, [$this, 'handleClassifyingForTracing']);
        $events->listen(Classified::class, [$this, 'handleClassifiedForTracing']);

        // laravel/ai dispatches no event for a failed classification, so detect failures from the provider's HTTP response.
        // These run after the HTTP client integration's listeners, which restore the evaluate span as the current span.
        $events->listen(ResponseReceived::class, [$this, 'handleHttpResponseReceived']);
        $events->listen(ConnectionFailed::class, [$this, 'handleHttpConnectionFailed']);
        $events->listen(ProviderFailedOver::class, [$this, 'handleProviderFailedOver']);
    }

    public function handleClassifyingForTracing(Classifying $event): void
    {
        if (!$this->isTracingFeatureEnabled(self::FEATURE_KEY_EVALUATE)) {
            return;
        }

        $parentSpan = SentrySdk::getCurrentHub()->getSpan();
        if ($parentSpan === null || !$parentSpan->getSampled()) {
            return;
        }

        $data = new AiSpanDataBag([
            'gen_ai.operation.name' => 'evaluate',
            'gen_ai.request.model' => $event->model,
            'gen_ai.provider.name' => $event->provider->name(),
        ]);

        if ($this->shouldCollectGenAiInputs()) {
            $data->set('gen_ai.input.messages', ClassificationMessageFormatter::formatInputMessages($event->prompt->state, $event->prompt->questions));
        }

        $span = $parentSpan->startChild(
            SpanContext::make()
                ->setOp('gen_ai.evaluate')
                ->setData($data->toArray())
                ->setOrigin('auto.ai.laravel')
                ->setDescription('evaluate ' . $event->model)
        );

        $this->container()->make(AiIntegration::class)->attachSpanToConversation($span);

        $this->classifications->set($event->invocationId, new ClassificationInvocationData(
            $span,
            $parentSpan,
            $event->provider->name(),
            $event->model,
            AiProviderUrlResolver::host($event->provider)
        ));

        SentrySdk::getCurrentHub()->setSpan($span);
    }

    public function handleClassifiedForTracing(Classified $event): void
    {
        $classification = $this->classifications->pull($event->invocationId);

        if ($classification === null) {
            return;
        }

        $data = new AiSpanDataBag($classification->span->getData());
        $data->set('gen_ai.response.model', $event->response->meta->model);
        $data->setTokenUsage($event->response->usage);

        if ($this->shouldCollectGenAiOutputs()) {
            $data->set('gen_ai.output.messages', ClassificationMessageFormatter::formatOutputMessages($event->response->answers));
        }

        $classification->span->setData($data->toArray());
        $classification->finishSpan(SpanStatus::ok());
        $classification->restoreParentSpan();
    }

    public function handleHttpResponseReceived(ResponseReceived $event): void
    {
        $status = $event->response->status();
        if ($status < 400) {
            return;
        }

        $invocationId = $this->findClassificationForRequest($event->request->url());
        if ($invocationId === null) {
            return;
        }

        $this->finishFailedClassification($invocationId, SpanStatus::createFromHttpStatusCode($status));
    }

    public function handleHttpConnectionFailed(ConnectionFailed $event): void
    {
        $invocationId = $this->findClassificationForRequest($event->request->url());
        if ($invocationId === null) {
            return;
        }

        $this->finishFailedClassification($invocationId, SpanStatus::internalError());
    }

    public function handleProviderFailedOver(ProviderFailedOver $event): void
    {
        foreach ($this->classifications->newestFirst() as $invocationId => $classification) {
            if ($classification->providerName === $event->provider->name() && $classification->model === $event->model) {
                $this->finishFailedClassification($invocationId, SpanStatus::internalError());

                return;
            }
        }
    }

    private function finishFailedClassification(string $invocationId, SpanStatus $status): void
    {
        $classification = $this->classifications->pull($invocationId);
        if ($classification === null) {
            return;
        }

        $classification->finishSpan($status);
        $classification->restoreParentSpan();
    }

    private function findClassificationForRequest(string $url): ?string
    {
        $currentSpan = SentrySdk::getCurrentHub()->getSpan();
        if ($currentSpan === null) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $host = \is_string($host) ? strtolower($host) : null;

        foreach ($this->classifications->newestFirst() as $invocationId => $classification) {
            if ($classification->span !== $currentSpan) {
                continue;
            }

            if ($classification->providerHost !== null && $host !== $classification->providerHost) {
                return null;
            }

            return $invocationId;
        }

        return null;
    }
}
