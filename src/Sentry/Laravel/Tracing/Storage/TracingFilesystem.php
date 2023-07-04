<?php

namespace Sentry\Laravel\Tracing\Storage;

use Illuminate\Contracts\Filesystem\Cloud as CloudFilesystemContract;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Sentry\Tracing\SpanContext;
use function Sentry\trace;

class TracingFilesystem implements CloudFilesystemContract
{
    protected $filesystem;

    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * @template TResult
     * @param array<string, mixed> $data
     * @param callable(): TResult $originalCall
     * @return TResult
     */
    protected function withTracing(string $method, array $data, callable $originalCall)
    {
        $context = new SpanContext();
        $context->setOp("storage.{$method}");
        $context->setData($data);

        return trace($originalCall, $context);
    }

    public function assertExists($path, $content = null)
    {
        return $this->withTracing('assertExists', ['path' => $path], function () use ($path, $content) {
            // TODO assertExists only present on FilesystemAdapter
            return $this->filesystem->assertExists($path, $content);
        });
    }

    public function url($path)
    {
        return $this->withTracing('url', ['path' => $path], function () use ($path) {
            return $this->filesystem->url($path);
        });
    }

    public function exists($path)
    {
        // TODO: Implement exists() method.
    }

    public function get($path)
    {
        // TODO: Implement get() method.
    }

    public function readStream($path)
    {
        // TODO: Implement readStream() method.
    }

    public function put($path, $contents, $options = [])
    {
        return $this->withTracing('put', ['path' => $path], function () use ($path, $contents, $options) {
            return $this->filesystem->put($path, $contents, $options);
        });
    }

    public function writeStream($path, $resource, array $options = [])
    {
        // TODO: Implement writeStream() method.
    }

    public function getVisibility($path)
    {
        // TODO: Implement getVisibility() method.
    }

    public function setVisibility($path, $visibility)
    {
        // TODO: Implement setVisibility() method.
    }

    public function prepend($path, $data)
    {
        // TODO: Implement prepend() method.
    }

    public function append($path, $data)
    {
        // TODO: Implement append() method.
    }

    public function delete($paths)
    {
        // TODO: Implement delete() method.
    }

    public function copy($from, $to)
    {
        return $this->withTracing('copy', ['from' => $from, 'to' => $to], function () use ($from, $to) {
            return $this->filesystem->copy($from, $to);
        });
    }

    public function move($from, $to)
    {
        // TODO: Implement move() method.
    }

    public function size($path)
    {
        // TODO: Implement size() method.
    }

    public function lastModified($path)
    {
        // TODO: Implement lastModified() method.
    }

    public function files($directory = null, $recursive = false)
    {
        // TODO: Implement files() method.
    }

    public function allFiles($directory = null)
    {
        // TODO: Implement allFiles() method.
    }

    public function directories($directory = null, $recursive = false)
    {
        // TODO: Implement directories() method.
    }

    public function allDirectories($directory = null)
    {
        // TODO: Implement allDirectories() method.
    }

    public function makeDirectory($path)
    {
        // TODO: Implement makeDirectory() method.
    }

    public function deleteDirectory($directory)
    {
        // TODO: Implement deleteDirectory() method.
    }
}
