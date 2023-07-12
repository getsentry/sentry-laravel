<?php

namespace Sentry\Laravel\Features;

use Illuminate\Contracts\Filesystem\Cloud as CloudFilesystem;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemManager;
use RuntimeException;
use Sentry\Laravel\Tracing\Storage\TracingCloudFilesystem;
use Sentry\Laravel\Tracing\Storage\TracingFilesystem;

class StorageIntegration extends Feature
{
    private const STORAGE_DRIVER_NAME = 'sentry';

    public function isApplicable(): bool
    {
        return $this->isTracingFeatureEnabled('storage');
    }

    public function setup(): void
    {
        foreach (config('filesystems.disks') as $disk => $config) {
            $currentDriver = $config['driver'];

            if ($currentDriver === self::STORAGE_DRIVER_NAME) {
                continue;
            }

            config([
                "filesystems.disks.{$disk}.driver" => self::STORAGE_DRIVER_NAME,
                "filesystems.disks.{$disk}.sentry_disk_name" => $disk,
                "filesystems.disks.{$disk}.sentry_original_driver" => $config['driver'],
            ]);
        }

        $this->container()->afterResolving(FilesystemManager::class, static function (FilesystemManager $filesystemManager): void {
            $filesystemManager->extend(
                self::STORAGE_DRIVER_NAME,
                static function (Application $application, array $config) use ($filesystemManager): Filesystem {
                    if (empty($config['sentry_original_driver'])) {
                        throw new RuntimeException(sprintf('Missing `sentry_original_driver` config key for `%s` filesystem driver.', self::STORAGE_DRIVER_NAME));
                    }

                    if ($config['sentry_original_driver'] === self::STORAGE_DRIVER_NAME) {
                        throw new RuntimeException(sprintf('`sentry_original_driver` for Sentry storage integration cannot be the `%s` driver.', self::STORAGE_DRIVER_NAME));
                    }

                    $config['driver'] = $config['sentry_original_driver'];
                    unset($config['sentry_original_driver']);

                    $originalFilesystem = $filesystemManager->build($config);

                    return $originalFilesystem instanceof CloudFilesystem
                        ? new TracingCloudFilesystem($originalFilesystem, $config['sentry_disk_name'] ?? 'unknown', $config['driver'])
                        : new TracingFilesystem($originalFilesystem, $config['sentry_disk_name'] ?? 'unknown', $config['driver']);
                }
            );
        });
    }
}
