<?php

namespace Sentry\Laravel\Features;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Events\Classified;
use Laravel\Ai\Events\Classifying;
use Sentry\Laravel\Features\Ai\AiSpanDataBag;
use Sentry\Laravel\Features\Classification\ClassificationInvocationData;
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

        $span = $parentSpan->startChild(
            SpanContext::make()
                ->setOp('gen_ai.evaluate')
                ->setData($data->toArray())
                ->setOrigin('auto.ai.laravel')
                ->setDescription('evaluate ' . $event->model)
        );

        $this->classifications->set($event->invocationId, new ClassificationInvocationData($span, $parentSpan));

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

        $classification->span->setData($data->toArray());
        $classification->finishSpan(SpanStatus::ok());
        $classification->restoreParentSpan();
    }
}
