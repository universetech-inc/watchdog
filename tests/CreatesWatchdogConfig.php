<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Tests;

use UniverseTech\Watchdog\WatchdogConfig;

trait CreatesWatchdogConfig
{
    /**
     * @param array<string, mixed> $overrides WatchdogConfig constructor arguments by name
     */
    protected function watchdogConfig(array $overrides = []): WatchdogConfig
    {
        return new WatchdogConfig(...array_merge([
            'strategy' => 'backup_port',
            'reloadSignal' => SIGUSR2,
            'watchdogPidFile' => '/nonexistent/watchdog.pid',
            'serverPidFile' => '/nonexistent/server.pid',
            'mainPort' => 9501,
            'backupPort' => 9502,
            'php' => 'php',
            'artisan' => 'artisan',
            'startCommand' => 'serve',
            'portTimeout' => 20,
            'timeout' => 30,
            'envOverload' => false,
            'servers' => [],
        ], $overrides));
    }
}
