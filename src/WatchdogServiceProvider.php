<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\ServiceProvider;
use UniverseTech\Watchdog\Console\WatchdogStartCommand;
use UniverseTech\Watchdog\Console\WatchdogUpdateCommand;
use UniverseTech\Watchdog\PidFile\WatchdogPidFile;
use UniverseTech\Watchdog\Signal\SignalRegistrar;
use UniverseTech\Watchdog\Signal\SwooleSignalRegistrar;

class WatchdogServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/watchdog.php', 'watchdog');

        // Only set in servers started by the watchdog in reuse port mode.
        if ($port = (int) env(SharedPortConfigurator::ENV_PORT)) {
            SharedPortConfigurator::apply($this->app->make('config'), $port);
        }

        // Bound rather than autowired: the container would otherwise cache these as shared
        // instances, and the config snapshot and the pid file lock must not outlive a run.
        $this->app->bind(WatchdogConfig::class, fn ($app) => WatchdogConfig::fromRepository($app->make('config'), $app));
        $this->app->bind(WatchdogPidFile::class, fn ($app) => new WatchdogPidFile(
            $app->make(WatchdogConfig::class)->watchdogPidFile,
            $app->make(Filesystem::class)
        ));
        $this->app->bind(SignalRegistrar::class, SwooleSignalRegistrar::class);
        $this->app->bind(SupervisorFactory::class);

        $this->commands([
            WatchdogStartCommand::class,
            WatchdogUpdateCommand::class,
        ]);
    }

    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/watchdog.php' => $this->app->configPath('watchdog.php'),
            ], 'watchdog-config');
        }
    }
}
