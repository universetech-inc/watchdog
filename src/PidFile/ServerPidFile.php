<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\PidFile;

use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Filesystem\Filesystem;
use RuntimeException;

/**
 * The pid file every server writes (`server.settings.pid_file`).
 *
 * All servers share it, and Swoole unlinks it on shutdown regardless of its content, so the
 * watchdog clears it before each start and rewrites it after a restart.
 */
class ServerPidFile
{
    public function __construct(
        protected string $path,
        protected Filesystem $filesystem
    ) {
    }

    /**
     * Null without a file, which a server shutting down may unlink at any time.
     */
    public function read(): ?int
    {
        try {
            return (int) $this->filesystem->get($this->path);
        } catch (FileNotFoundException) {
            return null;
        }
    }

    public function write(int $pid): void
    {
        if (@$this->filesystem->put($this->path, (string) $pid) === false) {
            throw new RuntimeException("Failed to write server pid file [{$this->path}].");
        }
    }

    public function clear(): void
    {
        $this->filesystem->delete($this->path);
    }
}
