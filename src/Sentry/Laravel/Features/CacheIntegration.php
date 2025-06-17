<?php

namespace Sentry\Laravel\Features;

use Illuminate\Cache\Events;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Redis\Events as RedisEvents;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Str;
use Sentry\Breadcrumb;
use Sentry\Laravel\Features\Concerns\ResolvesEventOrigin;
use Sentry\Laravel\Features\Concerns\TracksPushedScopesAndSpans;
use Sentry\Laravel\Features\Concerns\WorksWithSpans;
use Sentry\Laravel\Integration;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;

class CacheIntegration extends Feature
{
    use WorksWithSpans, TracksPushedScopesAndSpans, ResolvesEventOrigin;

    public function isApplicable(): bool
    {
        return $this->isTracingFeatureEnabled('redis_commands', false)
            || $this->isTracingFeatureEnabled('cache')
            || $this->isBreadcrumbFeatureEnabled('cache');
    }

    public function onBoot(Dispatcher $events): void
    {
        if ($this->isBreadcrumbFeatureEnabled('cache')) {
            $events->listen([
                Events\CacheHit::class,
                Events\CacheMissed::class,
                Events\KeyWritten::class,
                Events\KeyForgotten::class,
            ], [$this, 'handleCacheEventsForBreadcrumbs']);
        }

        if ($this->isTracingFeatureEnabled('cache')) {
            $events->listen([
                Events\RetrievingKey::class,
                Events\RetrievingManyKeys::class,
                Events\CacheHit::class,
                Events\CacheMissed::class,

                Events\WritingKey::class,
                Events\WritingManyKeys::class,
                Events\KeyWritten::class,
                Events\KeyWriteFailed::class,

                Events\ForgettingKey::class,
                Events\KeyForgotten::class,
                Events\KeyForgetFailed::class,
            ], [$this, 'handleCacheEventsForTracing']);
        }

        if ($this->isTracingFeatureEnabled('redis_commands', false)) {
            $events->listen(RedisEvents\CommandExecuted::class, [$this, 'handleRedisCommands']);

            $this->container()->afterResolving(RedisManager::class, static function (RedisManager $redis): void {
                $redis->enableEvents();
            });
        }
    }

    public function handleCacheEventsForBreadcrumbs(Events\CacheEvent $event): void
    {
        switch (true) {
            case $event instanceof Events\KeyWritten:
                $message = 'Written';
                break;
            case $event instanceof Events\KeyForgotten:
                $message = 'Forgotten';
                break;
            case $event instanceof Events\CacheMissed:
                $message = 'Missed';
                break;
            case $event instanceof Events\CacheHit:
                $message = 'Read';
                break;
            default:
                // In case events are added in the future we do nothing when an unknown event is encountered
                return;
        }

        $displayKey = $this->replaceSessionKey($event->key);

        Integration::addBreadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_DEFAULT,
            'cache',
            "{$message}: {$displayKey}",
            $event->tags ? ['tags' => $event->tags] : []
        ));
    }

    public function handleCacheEventsForTracing(Events\CacheEvent $event): void
    {
        if ($this->maybeHandleCacheEventAsEndOfSpan($event)) {
            return;
        }

        $this->withParentSpanIfSampled(function (Span $parentSpan) use ($event) {
            if ($event instanceof Events\RetrievingKey || $event instanceof Events\RetrievingManyKeys) {
                $keys = $this->normalizeKeyOrKeys(
                    $event instanceof Events\RetrievingKey
                        ? [$event->key]
                        : $event->keys
                );

                $displayKeys = $this->replaceSessionKeys($keys);

                $this->pushSpan(
                    $parentSpan->startChild(
                        SpanContext::make()
                            ->setOp('cache.get')
                            ->setData([
                                'cache.key' => $displayKeys,
                            ])
                            ->setOrigin('auto.cache')
                            ->setDescription(implode(', ', $displayKeys))
                    )
                );
            }

            if ($event instanceof Events\WritingKey || $event instanceof Events\WritingManyKeys) {
                $keys = $this->normalizeKeyOrKeys(
                    $event instanceof Events\WritingKey
                        ? [$event->key]
                        : $event->keys
                );

                $displayKeys = $this->replaceSessionKeys($keys);

                $this->pushSpan(
                    $parentSpan->startChild(
                        SpanContext::make()
                            ->setOp('cache.put')
                            ->setData([
                                'cache.key' => $displayKeys,
                                'cache.ttl' => $event->seconds,
                            ])
                            ->setOrigin('auto.cache')
                            ->setDescription(implode(', ', $displayKeys))
                    )
                );
            }

            if ($event instanceof Events\ForgettingKey) {
                $displayKey = $this->replaceSessionKey($event->key);

                $this->pushSpan(
                    $parentSpan->startChild(
                        SpanContext::make()
                            ->setOp('cache.remove')
                            ->setData([
                                'cache.key' => [$displayKey],
                            ])
                            ->setOrigin('auto.cache')
                            ->setDescription($displayKey)
                    )
                );
            }
        });
    }

    public function handleRedisCommands(RedisEvents\CommandExecuted $event): void
    {
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        // If there is no sampled span there is no need to handle the event
        if ($parentSpan === null || !$parentSpan->getSampled()) {
            return;
        }

        $context = SpanContext::make()
            ->setOp('db.redis')
            ->setOrigin('auto.cache.redis');

        $keyForDescription = '';

        // If the first parameter is a string and does not contain a newline we use it as the description since it's most likely a key
        // This is not a perfect solution but it's the best we can do without understanding the command that was executed
        if (!empty($event->parameters[0]) && is_string($event->parameters[0]) && !Str::contains($event->parameters[0], "\n")) {
            $keyForDescription = $this->replaceSessionKey($event->parameters[0]);
        }

        $context->setDescription(rtrim(strtoupper($event->command) . ' ' . $keyForDescription));
        $context->setStartTimestamp(microtime(true) - $event->time / 1000);
        $context->setEndTimestamp($context->getStartTimestamp() + $event->time / 1000);

        $data = [
            'db.redis.connection' => $event->connectionName,
        ];

        if ($this->shouldSendDefaultPii()) {
            // Replace session keys in parameters if present
            $parameters = $event->parameters;
            if (!empty($parameters[0]) && is_string($parameters[0])) {
                $parameters[0] = $this->replaceSessionKey($parameters[0]);
            }
            $data['db.redis.parameters'] = $parameters;
        }

        if ($this->isTracingFeatureEnabled('redis_origin')) {
            $commandOrigin = $this->resolveEventOrigin();

            if ($commandOrigin !== null) {
                $data = array_merge($data, $commandOrigin);
            }
        }

        $context->setData($data);

        $parentSpan->startChild($context);
    }

    private function maybeHandleCacheEventAsEndOfSpan(Events\CacheEvent $event): bool
    {
        // End of span for RetrievingKey and RetrievingManyKeys events
        if ($event instanceof Events\CacheHit || $event instanceof Events\CacheMissed) {
            $finishedSpan = $this->maybeFinishSpan(SpanStatus::ok());

            if ($finishedSpan !== null && count($finishedSpan->getData()['cache.key'] ?? []) === 1) {
                $finishedSpan->setData(array_merge(
                    $finishedSpan->getData(),
                    ['cache.hit' => $event instanceof Events\CacheHit]
                ));
            }

            return true;
        }

        // End of span for WritingKey and WritingManyKeys events
        if ($event instanceof Events\KeyWritten || $event instanceof Events\KeyWriteFailed) {
            $finishedSpan = $this->maybeFinishSpan(
                $event instanceof Events\KeyWritten ? SpanStatus::ok() : SpanStatus::internalError()
            );

            if ($finishedSpan !== null) {
                $finishedSpan->setData(array_merge(
                    $finishedSpan->getData(),
                    ['cache.success' => $event instanceof Events\KeyWritten]
                ));
            }

            return true;
        }

        // End of span for ForgettingKey event
        if ($event instanceof Events\KeyForgotten || $event instanceof Events\KeyForgetFailed) {
            $this->maybeFinishSpan();

            return true;
        }

        return false;
    }

    /**
     * Check if a cache key is the current session key.
     *
     * @param string $key
     *
     * @return bool
     */
    private function isSessionKey(string $key): bool
    {
        // Check if the container has a bound request and session
        if (!$this->container()->bound('request')) {
            return false;
        }

        try {
            $request = $this->container()->make('request');

            // Check if the request has a session
            if (!method_exists($request, 'hasSession') || !$request->hasSession()) {
                return false;
            }

            $session = $request->session();

            // Don't start the session if it hasn't been started yet
            if (!method_exists($session, 'isStarted') || !$session->isStarted()) {
                return false;
            }

            // Get the session ID and check if the cache key matches exactly
            $sessionId = $session->getId();
            
            // Check for empty session ID
            if (empty($sessionId)) {
                return false;
            }

            // Check if the key equals the session ID exactly
            return $key === $sessionId;
        } catch (\Exception $e) {
            // If anything goes wrong, we assume it's not a session key
            return false;
        }
    }

    /**
     * Replace a session key with a placeholder.
     *
     * @param string $key
     *
     * @return string
     */
    private function replaceSessionKey(string $key): string
    {
        return $this->isSessionKey($key) ? '{sessionKey}' : $key;
    }

    /**
     * Replace session keys in an array of keys with placeholders.
     *
     * @param string[] $keys
     *
     * @return string[]
     */
    private function replaceSessionKeys(array $keys): array
    {
        return array_map(function ($key) {
            return $this->replaceSessionKey($key);
        }, $keys);
    }

    /**
     * Normalize the array of keys to a array of only strings.
     *
     * @param string|string[]|array<array-key, mixed> $keyOrKeys
     *
     * @return string[]
     */
    private function normalizeKeyOrKeys($keyOrKeys): array
    {
        if (is_string($keyOrKeys)) {
            return [$keyOrKeys];
        }

        return collect($keyOrKeys)->map(function ($value, $key) {
            return is_string($key) ? $key : $value;
        })->values()->all();
    }
}
