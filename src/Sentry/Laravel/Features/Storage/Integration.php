<?php

namespace Sentry\Laravel\Features\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\AwsS3V3Adapter;
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

    /**
     * Decorates the configuration for all disks with Sentry driver configuration.
     *
     * This replaces the drivers with a custom driver that will capture performance traces and breadcrumbs.
     * The custom driver will be an instance of @see \Sentry\Laravel\Features\Storage\SentryS3V3Adapter
     * if the original driver was an @see \Illuminate\Filesystem\AwsS3V3Adapter,
     * and an instance of @see \Sentry\Laravel\Features\Storage\SentryFilesystemAdapter
     * which extends @see \Illuminate\Filesystem\FilesystemAdapter in all other cases.
     * You might run into problems if you expect another specific driver class.
     *
     * @param array<string, array<string, mixed>> $diskConfigs
     *
     * @return array<string, array<string, mixed>>
     */
    public static function configureDisks(array $diskConfigs, bool $enableSpans = true, bool $enableBreadcrumbs = true): array
    {
        $diskConfigsWithSentryDriver = [];
        foreach ($diskConfigs as $diskName => $diskConfig) {
            $diskConfigsWithSentryDriver[$diskName] = static::configureDisk($diskName, $diskConfig, $enableSpans, $enableBreadcrumbs);
        }

        return $diskConfigsWithSentryDriver;
    }

    /**
     * Decorates the configuration for a single disk with Sentry driver configuration.
     *
     * @see self::configureDisks()
     *
     * @param array<string, mixed> $diskConfig
     *
     * @return array<string, mixed>
     */
    public static function configureDisk(string $diskName, array $diskConfig, bool $enableSpans = true, bool $enableBreadcrumbs = true): array
    {
        $currentDriver = $diskConfig['driver'];

        if ($currentDriver !== self::STORAGE_DRIVER_NAME) {
            $diskConfig['driver'] = self::STORAGE_DRIVER_NAME;
            $diskConfig['sentry_disk_name'] = $diskName;
            $diskConfig['sentry_original_driver'] = $currentDriver;
            $diskConfig['sentry_enable_spans'] = $enableSpans;
            $diskConfig['sentry_enable_breadcrumbs'] = $enableBreadcrumbs;
        }

        return $diskConfig;
    }

    public function setup(): void
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

                        return $this->resolve($disk);
                    })->bindTo($filesystemManager, FilesystemManager::class);

                    $originalFilesystem = $diskResolver($disk, $config);

                    $defaultData = ['disk' => $disk, 'driver' => $config['driver']];

                    $recordSpans = $config['sentry_enable_spans'] ?? $this->isTracingFeatureEnabled(self::FEATURE_KEY);
                    $recordBreadcrumbs = $config['sentry_enable_breadcrumbs'] ?? $this->isBreadcrumbFeatureEnabled(self::FEATURE_KEY);

                    return $originalFilesystem instanceof AwsS3V3Adapter
                        ? new SentryS3V3Adapter($originalFilesystem, $defaultData, $recordSpans, $recordBreadcrumbs)
                        : new SentryFilesystemAdapter($originalFilesystem, $defaultData, $recordSpans, $recordBreadcrumbs);
                }
            );
        });
    }
}
