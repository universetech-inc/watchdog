<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Console;

use Hypervel\Console\Command;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'watchdog:update')]
class WatchdogUpdateCommand extends Command
{
    protected ?string $signature = 'watchdog:update';

    protected string $description = 'Broadcast update signal to watchdog process.';

    public function __construct(
        protected ApplicationContract $app,
        protected Repository $config,
        protected Filesystem $filesystem
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $pidFile = $this->config->get('watchdog.watchdog_pid_file', $this->app->storagePath('framework/watchdog.pid'));
        if (! $this->isWatchdogRunning($pidFile)) {
            $this->error('Watchdog process is not running.');
            return self::FAILURE;
        }

        if (! $pid = (int) $this->filesystem->get($pidFile)) {
            $this->error('Pid file is invalid.');
            return self::FAILURE;
        }

        if (! posix_kill($pid, (int) $this->config->get('watchdog.reload_signal', SIGUSR2))) {
            $reason = posix_strerror(posix_get_last_error());
            $this->error("Broadcast update signal to watchdog process [{$pid}] failed: {$reason}.");
            return self::FAILURE;
        }

        $this->info("Broadcast update signal to watchdog process [{$pid}] successfully.");

        return self::SUCCESS;
    }

    /**
     * A running watchdog holds an exclusive lock on its pid file.
     *
     * Checking the lock instead of the pid keeps a stale pid file from sending the reload
     * signal to an unrelated process that reused the pid; SIGUSR2 would terminate it.
     */
    protected function isWatchdogRunning(string $pidFile): bool
    {
        $handle = @fopen($pidFile, 'r');
        if ($handle === false) {
            return false;
        }

        $locked = ! flock($handle, LOCK_SH | LOCK_NB);
        fclose($handle);

        return $locked;
    }
}
