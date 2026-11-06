<?php

namespace Sentry\Laravel\Features;

use Illuminate\Auth\Access\Gate;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Database\Eloquent\Model;
use Sentry\Laravel\Features\Gate\TracingGate;
use Throwable;

class GateIntegration extends Feature
{
    private const FEATURE_KEY = 'gates';

    public function isApplicable(): bool
    {
        return $this->isTracingFeatureEnabled(self::FEATURE_KEY, false);
    }

    public function register(): void
    {
        // The gate was resolved before the hook was registered, so swapping it would split its state
        if ($this->container()->resolved(GateContract::class)) {
            return;
        }

        $this->container()->extend(GateContract::class, function ($gate) {
            // The config is checked here instead of in `register()` because the gate is resolved lazily
            if (!$this->isTracingFeatureEnabled(self::FEATURE_KEY, false) || !$this->hasDsnOrSpotlight()) {
                return $gate;
            }

            // Only the stock gate is replaced, custom gate implementations are left untouched
            if (get_class($gate) !== Gate::class) {
                return $gate;
            }

            try {
                return TracingGate::fromGate($gate);
            } catch (Throwable $exception) {
                // If the gate cannot be swapped we don't want to break authorization for the application
                return $gate;
            }
        });
    }

    /**
     * @internal
     *
     * @param mixed $result
     */
    public static function isAllowed($result): bool
    {
        if ($result instanceof Response) {
            return $result->allowed();
        }

        return (bool)$result;
    }

    /**
     * @internal
     *
     * @param mixed $ability
     */
    public static function formatAbility($ability): string
    {
        if ($ability instanceof \BackedEnum) {
            return (string)$ability->value;
        }

        if ($ability instanceof \UnitEnum) {
            return $ability->name;
        }

        return (string)$ability;
    }

    /**
     * @internal
     */
    public static function formatDescription(string $ability, array $arguments): string
    {
        // The gate resolves policies from the first argument, so its class names what is being authorized
        $subject = $arguments[0] ?? null;

        if (is_object($subject)) {
            return "{$ability} " . get_class($subject);
        }

        if (is_string($subject) && class_exists($subject)) {
            return "{$ability} {$subject}";
        }

        return $ability;
    }

    /**
     * @internal
     *
     * @return array<int, string>
     */
    public static function formatArguments(array $arguments): array
    {
        return array_values(array_map(static function ($argument): string {
            if ($argument instanceof Model) {
                $key = $argument->getKey();

                // Keys that are not scalar (e.g. composite keys) cannot be converted to a string
                return is_scalar($key) ? get_class($argument) . "({$key})" : get_class($argument);
            }

            if (is_object($argument)) {
                return get_class($argument);
            }

            // Class names are passed for abilities that don't need a model instance like `create`
            if (is_string($argument) && class_exists($argument)) {
                return $argument;
            }

            return gettype($argument);
        }, $arguments));
    }

    private function hasDsnOrSpotlight(): bool
    {
        $config = $this->getUserConfig();

        return !empty($config['dsn']) || ($config['spotlight'] ?? false) === true;
    }
}
