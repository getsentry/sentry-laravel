<?php

namespace Sentry\Laravel\Features\Gate;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Gate;
use Illuminate\Support\Arr;
use ReflectionClass;
use Sentry\Laravel\Features\GateIntegration;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Throwable;

/**
 * Records a span around every gate check.
 *
 * All gate checks (`allows`, `denies`, `check`, `any`, `authorize`, `inspect`, `@can`, `$user->can()`)
 * end up in `raw()`, so wrapping that single method covers them all. Policies and ability callbacks run
 * inside the span, which means queries they execute are recorded as children of the gate span.
 *
 * @internal
 */
class TracingGate extends Gate
{
    /**
     * Create a tracing gate that holds the same state as the given gate.
     */
    public static function fromGate(Gate $gate): self
    {
        /** @var self $tracingGate */
        $tracingGate = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();

        // All the gate state is held in protected properties which are accessible from this class
        foreach (get_object_vars($gate) as $property => $value) {
            $tracingGate->{$property} = $value;
        }

        return $tracingGate;
    }

    public function raw($ability, $arguments = [])
    {
        $hub = SentrySdk::getCurrentHub();

        $parentSpan = $hub->getSpan();

        // If there is no sampled span there is no need to record the gate check
        if ($parentSpan === null || !$parentSpan->getSampled()) {
            return parent::raw($ability, $arguments);
        }

        $formattedAbility = GateIntegration::formatAbility($ability);
        $wrappedArguments = Arr::wrap($arguments);

        $span = $parentSpan->startChild(
            SpanContext::make()
                ->setOp('gate.check')
                ->setOrigin('auto.laravel.gate')
                ->setDescription(GateIntegration::formatDescription($formattedAbility, $wrappedArguments))
                ->setData([
                    'gate.ability' => $formattedAbility,
                    'gate.arguments' => GateIntegration::formatArguments($wrappedArguments),
                ])
        );

        $hub->setSpan($span);

        try {
            $result = parent::raw($ability, $arguments);

            $allowed = GateIntegration::isAllowed($result);

            $span->setData([
                'gate.result' => $allowed ? 'allowed' : 'denied',
            ]);
            $span->setStatus($allowed ? SpanStatus::ok() : SpanStatus::permissionDenied());

            return $result;
        } catch (AuthorizationException $exception) {
            $span->setData(['gate.result' => 'denied']);
            $span->setStatus(SpanStatus::permissionDenied());

            throw $exception;
        } catch (Throwable $exception) {
            $span->setStatus(SpanStatus::internalError());

            throw $exception;
        } finally {
            $span->finish();

            $hub->setSpan($parentSpan);
        }
    }
}
