<?php

namespace Sentry\Laravel\Tracing\Storage;

use Exception;
use Illuminate\Contracts\Filesystem\Cloud as CloudFilesystemContract;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Sentry\Tracing\SpanContext;
use function Sentry\trace;

/**
 * Decorates the underlying filesystem by wrapping all calls to it with tracing.
 *
 * Parameters such as paths, directories or options are attached to the span as data,
 * parameters that contain file contents are omitted due to potential problems with
 * payload size or sensitive data.
 */
class TracingFilesystem implements CloudFilesystemContract
{
    /** @var Filesystem */
    protected $filesystem;

    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * @param list<mixed> $args
     * @param array<string, mixed> $data
     */
    protected function withTracing(string $method, array $args, array $data)
    {
        $context = new SpanContext();
        $context->setOp("file.{$method}"); // See https://develop.sentry.dev/sdk/performance/span-operations/#web-server
        $context->setData($data);

        return trace(function () use ($method, $args) {
            return $this->filesystem->{$method}(...$args);
        }, $context);
    }

    protected function assertFilesystemIsFilesystemAdapter(): void
    {
        if (! $this->filesystem instanceof FilesystemAdapter) {
            $requiredClass = FilesystemAdapter::class;
            $actualClass = get_class($this->filesystem);
            throw new Exception("The wrapped filesystem must be an instance of {$requiredClass}, got: {$actualClass}.");
        }
    }

    public function assertExists($path, $content = null)
    {
        $this->assertFilesystemIsFilesystemAdapter();

        return $this->withTracing(__FUNCTION__, func_get_args(), compact('path'));
    }

    public function assertMissing($path)
    {
        $this->assertFilesystemIsFilesystemAdapter();

        return $this->withTracing(__FUNCTION__, func_get_args(), compact('path'));
    }

    public function assertDirectoryEmpty($path)
    {
        $this->assertFilesystemIsFilesystemAdapter();

        return $this->withTracing(__FUNCTION__, func_get_args(), compact('path'));
    }

    public function url($path)
    {
        return $this->withTracing(__FUNCTION__, func_get_args(), compact('path'));
    }

    public function exists($path)
    {
        return $this->withTracing(__FUNCTION__, func_get_args(), compact('path'));
    }

    public function get($path)
    {
        return $this->withTracing(__FUNCTION__, func_get_args(), compact('path'));
    }

    public function readStream($path)
    {
        return $this->withTracing(__FUNCTION__, func_get_args(), compact('path'));
    }

    public function put($path, $contents, $options = [])
    {
        return $this->withTracing(__FUNCTION__, func_get_args(), compact('path', 'options'));
    }

    public function writeStream($path, $resource, array $options = [])
    {
        return $this->withTracing(__FUNCTION__, func_get_args(), compact('path', 'options'));
    }

    public function getVisibility($path)
    {
        return $this->withTracing(__FUNCTION__, func_get_args(), compact('path'));
    }

    public function setVisibility($path, $visibility)
    {
        return $this->withTracing(__FUNCTION__, func_get_args(), compact('path', 'visibility'));
    }

    public function prepend($path, $data)
    {
        return $this->withTracing(__FUNCTION__, func_get_args(), compact('path'));
    }

    public function append($path, $data)
    {
        return $this->withTracing(__FUNCTION__, func_get_args(), compact('path'));
    }

    public function delete($paths)
    {
        return $this->withTracing(__FUNCTION__, func_get_args(), compact('paths'));
    }

    public function copy($from, $to)
    {
        return $this->withTracing(__FUNCTION__, func_get_args(), compact('from', 'to'));
    }

    public function move($from, $to)
    {
        return $this->withTracing(__FUNCTION__, func_get_args(), compact('from', 'to'));
    }

    public function size($path)
    {
        return $this->withTracing(__FUNCTION__, func_get_args(), compact('path'));
    }

    public function lastModified($path)
    {
        return $this->withTracing(__FUNCTION__, func_get_args(), compact('path'));
    }

    public function files($directory = null, $recursive = false)
    {
        return $this->withTracing(__FUNCTION__, func_get_args(), compact('directory', 'recursive'));
    }

    public function allFiles($directory = null)
    {
        return $this->withTracing(__FUNCTION__, func_get_args(), compact('directory'));
    }

    public function directories($directory = null, $recursive = false)
    {
        return $this->withTracing(__FUNCTION__, func_get_args(), compact('directory', 'recursive'));
    }

    public function allDirectories($directory = null)
    {
        return $this->withTracing(__FUNCTION__, func_get_args(), compact('directory'));
    }

    public function makeDirectory($path)
    {
        return $this->withTracing(__FUNCTION__, func_get_args(), compact('path'));
    }

    public function deleteDirectory($directory)
    {
        return $this->withTracing(__FUNCTION__, func_get_args(), compact('directory'));
    }
}
