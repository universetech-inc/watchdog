<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Tests\Unit;

use UniverseTech\Watchdog\PidFile\WatchdogPidFile;
use UniverseTech\Watchdog\SharedPortConfigurator;
use UniverseTech\Watchdog\Signal\SignalRegistrar;
use UniverseTech\Watchdog\Signal\SwooleSignalRegistrar;
use UniverseTech\Watchdog\SupervisorFactory;
use UniverseTech\Watchdog\Tests\TestCase;
use UniverseTech\Watchdog\WatchdogServiceProvider;

class WatchdogServiceProviderTest extends TestCase
{
    public function testConfigIsMerged(): void
    {
        $this->assertSame('backup_port', $this->app->make('config')->get('watchdog.strategy'));
        $this->assertSame(SIGUSR2, $this->app->make('config')->get('watchdog.reload_signal'));
    }

    public function testStatefulServicesAreNotShared(): void
    {
        $this->assertNotSame($this->app->make(WatchdogPidFile::class), $this->app->make(WatchdogPidFile::class));
        $this->assertNotSame($this->app->make(SupervisorFactory::class), $this->app->make(SupervisorFactory::class));
    }

    public function testSignalsUseSwoole(): void
    {
        $this->app->forgetInstance(SignalRegistrar::class);

        $this->assertInstanceOf(SwooleSignalRegistrar::class, $this->app->make(SignalRegistrar::class));
    }

    public function testServersAreLeftAloneOutsideTheWatchdog(): void
    {
        $servers = $this->app->make('config')->get('server.servers');

        $this->assertNotContains(SharedPortConfigurator::ANCHOR_NAME, array_column($servers, 'name'));
    }

    public function testServerStartedInReusePortModeGetsTheAnchor(): void
    {
        $this->app->make('config')->set('server.servers', [['name' => 'http', 'port' => 8000]]);

        putenv(SharedPortConfigurator::ENV_PORT . '=9601');
        try {
            (new WatchdogServiceProvider($this->app))->register();
        } finally {
            putenv(SharedPortConfigurator::ENV_PORT);
        }

        $servers = $this->app->make('config')->get('server.servers');
        $this->assertSame([SharedPortConfigurator::ANCHOR_NAME, 'http'], array_column($servers, 'name'));
        $this->assertSame(9601, $servers[1]['port']);
    }
}
