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

    public function handle()
    {
        $pidFile = $this->config->get('watchdog.watchdog_pid_file', $this->app->storagePath('framework/watchdog.pid'));
        if (! $this->filesystem->isFile($pidFile)) {
            $this->error('Watchdog process is not running.');
            return;
        }

        if (! $pid = (int) $this->filesystem->get($pidFile)) {
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
