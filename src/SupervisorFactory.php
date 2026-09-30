<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UniverseTech\Watchdog\PidFile\ServerPidFile;
use UniverseTech\Watchdog\PidFile\WatchdogPidFile;
use UniverseTech\Watchdog\Process\ProcessServerLauncher;
use UniverseTech\Watchdog\Process\ServerCommand;
use UniverseTech\Watchdog\Signal\SignalRegistrar;
use UniverseTech\Watchdog\Strategy\BackupPortStrategy;
use UniverseTech\Watchdog\Strategy\RestartStrategy;
use UniverseTech\Watchdog\Strategy\ReusePortStrategy;
use UniverseTech\Watchdog\Strategy\StrategyType;

/**
 * Builds the objects of one watchdog run, which depend on the command's output.
 */
class SupervisorFactory
{
    public function __construct(
        protected ApplicationContract $app,
        protected WatchdogConfig $config,
        protected WatchdogPidFile $watchdogPidFile,
        protected SignalRegistrar $signals,
        protected Filesystem $filesystem
    ) {
    }

    public function create(LoggerInterface $logger, OutputInterface $output): Supervisor
    {
        $strategy = $this->createStrategy($logger);
        $serverPidFile = new ServerPidFile($this->config->serverPidFile, $this->filesystem);
        $events = new EventChannel();
        $launcher = new ProcessServerLauncher(
            $this->config,
            new ServerCommand($this->config, $strategy, $this->app->environmentFilePath()),
            $serverPidFile,
            $events,
            $logger,
            $output
        );

        return new Supervisor(
            $this->config,
            $strategy,
            $launcher,
            $events,
            $this->signals,
            $this->watchdogPidFile,
            $serverPidFile,
            $logger
        );
    }

    public function createStrategy(LoggerInterface $logger): RestartStrategy
    {
        return match ($this->config->strategyType()) {
            StrategyType::BackupPort => new BackupPortStrategy($this->config, $logger),
            StrategyType::ReusePort => new ReusePortStrategy($this->config, $logger),
        };
    }
}
