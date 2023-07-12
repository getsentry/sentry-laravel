<?php

namespace Sentry\Laravel\Tracing\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Sentry\Tracing\SpanContext;
use function Sentry\trace;

/**
 * Decorates the underlying filesystem by wrapping all calls to it with tracing.
 *
 * Parameters such as paths, directories or options are attached to the span as data,
 * parameters that contain file contents are omitted due to potential problems with
 * payload size or sensitive data.
 */
class TracingFilesystem implements Filesystem
{
    /** @var Filesystem */
    protected $filesystem;

    /** @var string */
    protected $disk;

    /** @var string */
    protected $driver;

    public function __construct(Filesystem $filesystem, string $disk, string $driver)
    {
        $this->filesystem = $filesystem;
        $this->disk = $disk;
        $this->driver = $driver;
    }

    /**
     * @param list<mixed> $args
     * @param array<string, mixed> $data
     */
    protected function withTracing(string $method, array $args, array $data)
    {
        $context = new SpanContext();
        $context->setOp("file.{$method}"); // See https://develop.sentry.dev/sdk/performance/span-operations/#web-server
        $context->setDescription(json_encode($data, JSON_PRETTY_PRINT));
        $context->setData(array_merge($data, [
            'disk' => $this->disk,
            'driver' => $this->driver,
        ]));

        return trace(function () use ($method, $args) {
            return $this->filesystem->{$method}(...$args);
        }, $context);
    }

    /** @see \Illuminate\Filesystem\FilesystemAdapter::assertExists() */
    public function assertExists($path, $content = null)
    {
        return $this->withTracing(__FUNCTION__, func_get_args(), compact('path'));
    }

    /** @see \Illuminate\Filesystem\FilesystemAdapter::assertMissing() */
    public function assertMissing($path)
    {
        return $this->withTracing(__FUNCTION__, func_get_args(), compact('path'));
    }

    /** @see \Illuminate\Filesystem\FilesystemAdapter::assertDirectoryEmpty() */
    public function assertDirectoryEmpty($path)
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

    public function __call($name, $arguments)
    {
        return $this->filesystem->{$name}(...$arguments);
    }
}
