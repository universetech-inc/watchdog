<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog;

use Hypervel\Support\ServiceProvider;
use UniverseTech\Watchdog\Console\WatchdogStartCommand;
use UniverseTech\Watchdog\Console\WatchdogUpdateCommand;

class WatchdogServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/watchdog.php', 'watchdog');

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
