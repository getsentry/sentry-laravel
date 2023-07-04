<?php

namespace Sentry\Laravel\Features;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemManager;
use Sentry\Laravel\Tracing\Storage\TracingFilesystem;

class StorageIntegration extends Feature
{
    public function isApplicable(): bool
    {
        return $this->isTracingFeatureEnabled('storage');
    }

    public function setup(): void
    {
        $this->container()->afterResolving(FilesystemManager::class, static function (FilesystemManager $filesystemManager): void {
            $filesystemManager->extend('sentry', function (Application $application, array $config) use ($filesystemManager): Filesystem {
                $config['driver'] = $config['original_driver'];
                unset($config['original_driver']);
                $originalFilesystem = $filesystemManager->build($config);

                return new TracingFilesystem($originalFilesystem);
            });
        });
    }

    public function setupInactive(): void
    {
        $this->setup();
    }
}
