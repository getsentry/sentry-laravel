<?php

namespace Sentry\Laravel\Features\Storage;

use Illuminate\Contracts\Filesystem\Cloud as CloudFilesystem;

class SentryCloudFilesystem extends SentryFilesystem implements CloudFilesystem
{
    /** @var CloudFilesystem */
    protected $filesystem;

    public function __construct(CloudFilesystem $filesystem, array $defaultData, bool $recordSpans, bool $recordBreadcrumbs)
    {
        parent::__construct($filesystem, $defaultData, $recordSpans, $recordBreadcrumbs);
    }

    public function url($path)
    {
        return $this->withSentry(__FUNCTION__, func_get_args(), $path, compact('path'));
    }
}
