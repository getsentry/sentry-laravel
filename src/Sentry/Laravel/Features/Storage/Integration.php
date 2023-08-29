<?php

namespace Sentry\Laravel\Features\Storage;

use Illuminate\Contracts\Filesystem\Cloud as CloudFilesystem;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemManager;
use RuntimeException;
use Sentry\Laravel\Features\Feature;

class Integration extends Feature
{
    private const FEATURE_KEY = 'storage';

    private const STORAGE_DRIVER_NAME = 'sentry';

    public function isApplicable(): bool
    {
        // Since we only register the driver this feature is always applicable
        return true;
    }

    public function onRegister(): void
    {
        $this->registerDiskDriver();
    }

    public function onRegisterInactive(): void
    {
        $this->registerDiskDriver();
    }

    private function registerDiskDriver(): void
    {
        $this->container()->afterResolving(FilesystemManager::class, function (FilesystemManager $filesystemManager): void {
            $filesystemManager->extend(
                self::STORAGE_DRIVER_NAME,
                function (Application $application, array $config) use ($filesystemManager): Filesystem {
                    if (empty($config['sentry_disk_name'])) {
                        throw new RuntimeException(sprintf('Missing `sentry_disk_name` config key for `%s` filesystem driver.', self::STORAGE_DRIVER_NAME));
                    }

                    if (empty($config['sentry_original_driver'])) {
                        throw new RuntimeException(sprintf('Missing `sentry_original_driver` config key for `%s` filesystem driver.', self::STORAGE_DRIVER_NAME));
                    }

                    if ($config['sentry_original_driver'] === self::STORAGE_DRIVER_NAME) {
                        throw new RuntimeException(sprintf('`sentry_original_driver` for Sentry storage integration cannot be the `%s` driver.', self::STORAGE_DRIVER_NAME));
                    }

                    $disk = $config['sentry_disk_name'];

                    $config['driver'] = $config['sentry_original_driver'];
                    unset($config['sentry_original_driver']);

                    $diskResolver = (function (string $disk, array $config) {
                        // This is a "hack" to make sure that the original driver is resolved by the FilesystemManager
                        config(["filesystems.disks.{$disk}" => $config]);

                        /** @var FilesystemManager $this */
                        return $this->resolve($disk);
                    })->bindTo($filesystemManager, FilesystemManager::class);

                    $originalFilesystem = $diskResolver($disk, $config);

                    $defaultData = ['disk' => $disk, 'driver' => $config['driver']];

                    $recordSpans = $config['sentry_enable_spans'] ?? $this->isTracingFeatureEnabled(self::FEATURE_KEY);
                    $recordBreadcrumbs = $config['sentry_enable_breadcrumbs'] ?? $this->isBreadcrumbFeatureEnabled(self::FEATURE_KEY);

                    return $originalFilesystem instanceof CloudFilesystem
                        ? new SentryCloudFilesystem($originalFilesystem, $defaultData, $recordSpans, $recordBreadcrumbs)
                        : new SentryFilesystem($originalFilesystem, $defaultData, $recordSpans, $recordBreadcrumbs);
                }
            );
        });
    }
}
