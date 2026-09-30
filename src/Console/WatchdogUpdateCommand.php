<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Console;

use Hypervel\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use UniverseTech\Watchdog\PidFile\WatchdogPidFile;
use UniverseTech\Watchdog\WatchdogConfig;

#[AsCommand(name: 'watchdog:update')]
class WatchdogUpdateCommand extends Command
{
    protected ?string $signature = 'watchdog:update';

    protected string $description = 'Broadcast update signal to watchdog process.';

    public function handle(WatchdogPidFile $pidFile, WatchdogConfig $config): int
    {
        $pid = $pidFile->isLocked() ? $pidFile->readPid() : null;
        if ($pid === null) {
            $this->error('Watchdog process is not running.');
            return self::FAILURE;
        }

        // posix_kill() signals a whole process group for 0 and negative pids.
        if ($pid < 1) {
            $this->error('Pid file is invalid.');
            return self::FAILURE;
        }

        if (! posix_kill($pid, $config->reloadSignal)) {
            $reason = posix_strerror(posix_get_last_error());
            $this->error("Broadcast update signal to watchdog process [{$pid}] failed: {$reason}.");
            return self::FAILURE;
        }

        $this->info("Broadcast update signal to watchdog process [{$pid}] successfully.");

        return self::SUCCESS;
    }
}
