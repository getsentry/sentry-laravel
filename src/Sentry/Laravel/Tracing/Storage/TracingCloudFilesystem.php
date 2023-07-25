<?php

namespace Sentry\Laravel\Tracing\Storage;

use Illuminate\Contracts\Filesystem\Cloud as CloudFilesystem;

class TracingCloudFilesystem extends TracingFilesystem implements CloudFilesystem
{
    /** @var CloudFilesystem */
    protected $filesystem;

    public function __construct(CloudFilesystem $filesystem, array $defaultData)
    {
        parent::__construct($filesystem, $defaultData);
    }

    public function url($path)
    {
        return $this->withTracing(__FUNCTION__, func_get_args(), $path, compact('path'));
    }
}
