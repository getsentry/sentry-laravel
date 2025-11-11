<?php

namespace Sentry\Laravel\Features;

use Illuminate\Cache\Events;
use Illuminate\Foundation\Application;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
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

    /**
     * Indicates whether to attempt to detect the session key when running in the console.
     *
     * @internal this is mainly intended for testing purposes.
     *
     * @var bool
     */
    public static $detectSessionKeyOnConsole = false;

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
            $data['db.redis.parameters'] = $this->replaceSessionKeys($event->parameters);
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
                $finishedSpan->setData([
                    'cache.hit' => $event instanceof Events\CacheHit
                ]);
            }

            return true;
        }

        // End of span for WritingKey and WritingManyKeys events
        if ($event instanceof Events\KeyWritten || $event instanceof Events\KeyWriteFailed) {
            $finishedSpan = $this->maybeFinishSpan(
                $event instanceof Events\KeyWritten ? SpanStatus::ok() : SpanStatus::internalError()
            );

            if ($finishedSpan !== null) {
                $finishedSpan->setData([
                    'cache.success' => $event instanceof Events\KeyWritten
                ]);
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
     * Retrieve the current session key if available.
     */
    private function getSessionKey(): ?string
    {
        $container = $this->container();

        try {
            // A session key is highly unusal to be available when running in the console
            // So we skip trying to get the session key in that case to prevent booting up the session store unnecessarily
            // Doing this anyway can result in unnecessary database connections for example
            // See: https://github.com/getsentry/sentry-laravel/issues/1057
            if (!self::$detectSessionKeyOnConsole && $container instanceof Application && $container->runningInConsole()) {
                return null;
            }

            /** @var Session $sessionStore */
            $sessionStore = $this->container()->make('session.store');

            // It is safe for us to get the session ID here without checking if the session is started
            // because getting the session ID does not start the session. In addition we need the ID before
            // the session is started because the cache will retrieve the session ID from the cache before the session
            // is considered started. So if we wait for the session to be started, we will not be able to replace the
            // session key in the cache operation that is being executed to retrieve the session data from the cache.
            return $sessionStore->getId();
        } catch (\Exception $e) {
            // We can assume the session store is not available here so there is no session key to retrieve
            // We capture a generic exception to avoid breaking the application because some code paths can
            // result in an exception other than the expected `Illuminate\Contracts\Container\BindingResolutionException`
            return null;
        }
    }

    /**
     * Replace a session key with a placeholder.
     */
    private function replaceSessionKey(?string $value): string
    {
        if (!is_string($value)) {
            return '{empty key}';
        }

        return $value === $this->getSessionKey() ? '{sessionKey}' : $value;
    }

    /**
     * Replace session keys in an array of keys with placeholders.
     *
     * @param string[] $values
     *
     * @return mixed[]
     */
    private function replaceSessionKeys(array $values): array
    {
        $sessionKey = $this->getSessionKey();

        return array_map(static function ($value) use ($sessionKey) {
            return is_string($value) && $value === $sessionKey ? '{sessionKey}' : $value;
        }, $values);
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
