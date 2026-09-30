<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Tests\Unit;

use RuntimeException;
use UniverseTech\Watchdog\Strategy\StrategyType;
use UniverseTech\Watchdog\Tests\TestCase;
use UniverseTech\Watchdog\WatchdogConfig;

class WatchdogConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = $this->app->make(WatchdogConfig::class);

        $this->assertSame(StrategyType::BackupPort, $config->strategyType());
        $this->assertSame(SIGUSR2, $config->reloadSignal);
        $this->assertSame($this->app->storagePath('framework/watchdog.pid'), $config->watchdogPidFile);
        $this->assertSame(9501, $config->mainPort);
        $this->assertSame(9502, $config->backupPort);
        $this->assertSame('serve', $config->startCommand);
        $this->assertSame(20, $config->portTimeout);
        $this->assertSame(30, $config->timeout);
        $this->assertTrue($config->envOverload);
    }

    public function testServerPidFileFallsBackToTheServerSettings(): void
    {
        $this->app->make('config')->set('watchdog.server_pid_file', null);
        $this->app->make('config')->set('server.settings.pid_file', '/run/app.pid');

        $this->assertSame('/run/app.pid', $this->app->make(WatchdogConfig::class)->serverPidFile);

        $this->app->make('config')->set('watchdog.server_pid_file', '/run/watchdog-server.pid');

        $this->assertSame('/run/watchdog-server.pid', $this->app->make(WatchdogConfig::class)->serverPidFile);
    }

    public function testValuesAreCast(): void
    {
        $this->app->make('config')->set('watchdog.server_ports.main', '8001');
        $this->app->make('config')->set('watchdog.env_overload', 0);

        $config = $this->app->make(WatchdogConfig::class);

        $this->assertSame(8001, $config->mainPort);
        $this->assertFalse($config->envOverload);
    }

    public function testUnknownStrategyFailsOnlyWhenTheStrategyIsNeeded(): void
    {
        $this->app->make('config')->set('watchdog.strategy', 'bogus');
        $config = $this->app->make(WatchdogConfig::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unknown watchdog strategy [bogus].');

        $config->strategyType();
    }
}
