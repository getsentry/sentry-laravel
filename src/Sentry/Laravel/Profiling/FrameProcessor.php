<?php

namespace Sentry\Laravel\Profiling;

use Illuminate\Support\Str;
use Sentry\Laravel\Tracing\BacktraceHelper;

class FrameProcessor
{
    /**
     * @param array $frame
     *
     * @return array
     */
    public function __invoke(array $frame): array
    {
        // Check if we are dealing with a frame for a cached view path
        if (Str::startsWith($frame['filename'], '/storage/framework/views/')) {
            $originalViewPath = $this->backtraceHelper()->getOriginalViewPathForCompiledViewPath($frame['abs_path']);

            if ($originalViewPath !== null) {
                // For views both the filename and function is the view path so we set them both to the original view path
                $frame['filename'] = $frame['function'] = $originalViewPath;
            }
        }

        return $frame;
    }

    private function backtraceHelper(): BacktraceHelper
    {
        static $helper = null;

        if ($helper === null) {
            $helper = app(BacktraceHelper::class);
        }

        return $helper;
    }
}
