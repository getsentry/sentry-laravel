<?php

declare(strict_types=1);

namespace Sentry\Laravel\Tracing;

use Illuminate\Contracts\Container\Container;
use ReflectionClass;
use Sentry\Tracing\SamplingContext;

class CallableProxy
{
    /**
     * @var Container
     */
    private $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * @param array|callable|null $tracesSampler
     *
     * @return array|callable|null
     */
    public function proxyTraceSampler($tracesSampler)
    {
        if ($tracesSampler === null) {
            return null;
        }

        if (is_callable($tracesSampler) === true) {
            return function ($context) use ($tracesSampler) {
                return $this->container->call($tracesSampler, [SamplingContext::class => $context]);
            };
        }

        if (is_array($tracesSampler) === false || count($tracesSampler) !== 2) {
            return $tracesSampler;
        }

        return function ($context) use ($tracesSampler) {
            $class = $tracesSampler[0];
            $method = $tracesSampler[1];

            $reflection = new ReflectionClass($class);
            $methodDefinition = $reflection->getMethod($method);

            // Check if static method is used otherwise create the class
            if ($methodDefinition->isStatic() === true) {
                $classOrObject = $class;
            } else {
                $classOrObject = $this->container->make($class);
            }

            return $this->container->call([$classOrObject, $method], [SamplingContext::class => $context]);
        };
    }
}
