<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Tests\Feature;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Server\ServerInterface;
use UniverseTech\Watchdog\PidFile\WatchdogPidFile;
use UniverseTech\Watchdog\Tests\TestCase;

class WatchdogStartCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->app->make('config');
        $config->set('watchdog.watchdog_pid_file', $this->temporaryPath('watchdog.pid'));
        $config->set('watchdog.server_pid_file', $this->temporaryPath('server.pid'));
        $config->set('watchdog.port_timeout', 1);
        $config->set('watchdog.timeout', 1);
    }

    public function testUnknownStrategyFails(): void
    {
        $this->app->make('config')->set('watchdog.strategy', 'bogus');

        $this->artisan('watchdog:start')
            ->expectsOutputToContain('Unknown watchdog strategy [bogus].')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->temporaryPath('watchdog.pid'));
    }

    public function testReusePortRefusesWebSocketServers(): void
    {
        $config = $this->app->make('config');
        $config->set('watchdog.strategy', 'reuse_port');
        $config->set('server.servers', [['name' => 'reverb', 'type' => ServerInterface::SERVER_WEBSOCKET]]);

        $this->artisan('watchdog:start')
            ->expectsOutputToContain('does not support WebSocket servers [reverb]')
            ->assertFailed();
    }

    public function testFailedInitialStartReleasesThePidFile(): void
    {
        $config = $this->app->make('config');
        $config->set('watchdog.server_ports.main', $this->freePort());
        $config->set('watchdog.command.php', '/usr/bin/false');

        $this->artisan('watchdog:start')
            ->expectsOutputToContain('Failed to start server.')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->temporaryPath('watchdog.pid'));
        $this->assertFalse((new WatchdogPidFile($this->temporaryPath('watchdog.pid'), new Filesystem()))->isLocked());
    }

    protected function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    }
}
