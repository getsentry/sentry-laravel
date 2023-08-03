<?php

namespace Sentry\Laravel\Features\Storage;

use Illuminate\Filesystem\FilesystemAdapter;

/**
 * Decorates the underlying filesystem by wrapping all calls to it with tracing.
 *
 * Parameters such as paths, directories or options are attached to the span as data,
 * parameters that contain file contents are omitted due to potential problems with
 * payload size or sensitive data.
 */
class SentryFilesystemAdapter extends FilesystemAdapter
{
    use FilesystemDecorator;

    public function __construct(FilesystemAdapter $filesystem, array $defaultData, bool $recordSpans, bool $recordBreadcrumbs)
    {
        parent::__construct($filesystem->getDriver(), $filesystem->getAdapter(), $filesystem->getConfig());

        $this->filesystem = $filesystem;
        $this->defaultData = $defaultData;
        $this->recordSpans = $recordSpans;
        $this->recordBreadcrumbs = $recordBreadcrumbs;
    }
}
