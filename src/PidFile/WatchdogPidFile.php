<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\PidFile;

use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Filesystem\Filesystem;
use RuntimeException;

/**
 * The watchdog's pid file, locked for as long as the watchdog runs.
 *
 * `watchdog:update` checks the lock to tell a running watchdog from a stale pid file, whose
 * pid may already belong to an unrelated process that the reload signal would terminate.
 * The lock also refuses a second watchdog.
 */
class WatchdogPidFile
{
    /**
     * @var null|resource
     */
    protected $handle = null;

    public function __construct(
        protected string $path,
        protected Filesystem $filesystem
    ) {
    }

    /**
     * Close-on-exec keeps the servers from inheriting the handle, which would keep the lock
     * held after the watchdog dies.
     */
    public function acquire(int $pid): void
    {
        $handle = fopen($this->path, 'ce');
        if ($handle === false) {
            throw new RuntimeException("Failed to open watchdog pid file [{$this->path}].");
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException("Another watchdog is already running [{$this->path}].");
        }

        ftruncate($handle, 0);
        fwrite($handle, (string) $pid);
        fflush($handle);

        $this->handle = $handle;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        // Delete before unlocking so the pid is never readable without the lock held.
        $this->filesystem->delete($this->path);
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function isLocked(): bool
    {
        $handle = @fopen($this->path, 'r');
        if ($handle === false) {
            return false;
        }

        $locked = ! flock($handle, LOCK_SH | LOCK_NB);
        fclose($handle);

        return $locked;
    }

    /**
     * Null once the file is gone, which a watchdog shutting down may do right after
     * `isLocked()` saw the lock.
     */
    public function readPid(): ?int
    {
        try {
            return (int) $this->filesystem->get($this->path);
        } catch (FileNotFoundException) {
            return null;
        }
    }
}
