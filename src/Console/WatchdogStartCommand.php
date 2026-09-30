<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Console;

use Hypervel\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use UniverseTech\Watchdog\SupervisorFactory;

#[AsCommand(name: 'watchdog:start')]
class WatchdogStartCommand extends Command
{
    protected ?string $signature = 'watchdog:start';

    protected string $description = 'Start watchdog for servers.';

    public function handle(SupervisorFactory $factory): int
    {
        try {
            $supervisor = $factory->create(new CommandLogger($this), $this->output);
            $supervisor->boot();
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        return $supervisor->run() ? self::SUCCESS : self::FAILURE;
    }
}
