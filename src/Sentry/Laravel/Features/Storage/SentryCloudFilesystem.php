<?php

namespace Sentry\Laravel\Features\Storage;

use Illuminate\Contracts\Filesystem\Cloud;

class SentryCloudFilesystem extends SentryFilesystem implements Cloud
{
}
