<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Console;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Support\Filesystem\Filesystem;
use LaravelHyperf\Foundation\Console\Command;

class WatchdogUpdateCommand extends Command
{
    protected ?string $signature = 'watchdog:update';

    protected string $description = 'Broadcast update signal to watchdog process.';

    public function __construct(
        protected ConfigInterface $config,
        protected Filesystem $filesystem
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $pidFile = $this->config->get('watchdog.server_pid_file' , BASE_PATH . '/runtime/watchdog.pid');
        if (! $this->filesystem->isFile($pidFile)) {
            $this->error('Watchdog process is not running.');
            return;
        }

        if (! $pid = (int) file_get_contents($pidFile)) {
            $this->error('Pid file is invalid.');
            return;
        }

        if (! posix_kill($pid, 0)) {
            $this->warn("Watchdog process [{$pid}] doesn't exist.");
            return;
        }

        if (! posix_kill($pid, SIGWINCH)) {
            $this->error("Broadcast update signal to watchdog process [{$pid}] failed.");
            return;
        }

        $this->info("Broadcast update signal to watchdog process [{$pid}] successfully.");
    }
}