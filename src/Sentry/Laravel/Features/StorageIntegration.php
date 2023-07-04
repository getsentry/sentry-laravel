<?php

namespace Sentry\Laravel\Features;

use Illuminate\Cache\Events;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Redis\Events as RedisEvents;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Str;
use Sentry\Breadcrumb;
use Sentry\Laravel\Features\Concerns\ResolvesEventOrigin;
use Sentry\Laravel\Integration;
use Sentry\Laravel\Tracing\Storage\TracingFilesystem;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;

class StorageIntegration extends Feature
{
    public function isApplicable(): bool
    {
        return $this->isTracingFeatureEnabled('storage');
    }

    public function setup(Dispatcher $events): void
    {
        if ($this->isTracingFeatureEnabled('storage', false)) {
            $this->container()->afterResolving(FilesystemManager::class, static function (FilesystemManager $filesystemManager): void {
                $filesystemManager->extend('sentry', function (Application $application, array $config) use ($filesystemManager): Filesystem {
                    $config['driver'] = $config['original_driver'];
                    unset($config['original_driver']);
                    $originalFilesystem = $filesystemManager->build($config);

                    return new TracingFilesystem($originalFilesystem);
                });
            });
        }
    }
}
