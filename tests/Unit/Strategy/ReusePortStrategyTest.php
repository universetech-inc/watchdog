<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Tests\Unit\Strategy;

use Hypervel\Server\ServerInterface;
use Hypervel\Testing\UnitTestCase;
use RuntimeException;
use UniverseTech\Watchdog\Strategy\ReusePortStrategy;
use UniverseTech\Watchdog\Tests\CreatesWatchdogConfig;
use UniverseTech\Watchdog\Tests\Fakes\FakeServerLauncher;
use UniverseTech\Watchdog\Tests\Fakes\InMemoryLogger;
use UniverseTech\Watchdog\Tests\UsesTemporaryDirectory;

class ReusePortStrategyTest extends UnitTestCase
{
    use CreatesWatchdogConfig;
    use UsesTemporaryDirectory;

    protected FakeServerLauncher $launcher;

    protected InMemoryLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->launcher = new FakeServerLauncher();
        $this->logger = new InMemoryLogger();
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();

        parent::tearDown();
    }

    public function testRestartStartsTheNewServerOnTheSamePortFirst(): void
    {
        $current = $this->launcher->start(9501);

        $next = $this->strategy()->restart($current, $this->launcher);

        $this->assertSame(['start 9501', 'start 9501 shared', 'stop 9501 (1000)'], $this->launcher->calls);
        $this->assertTrue($next->sharesPort);
        $this->assertSame([$next], array_values($this->launcher->servers()));
        $this->assertSame([
            'Starting new server on the same port...',
            'Stopping original server (pid: [1000])...',
        ], $this->logger->messages());
    }

    public function testNewServerFailingToStartLeavesTheOriginalServerAlone(): void
    {
        $current = $this->launcher->start(9501);
        $this->launcher->failStart[9501] = true;

        $this->expectException(RuntimeException::class);

        try {
            $this->strategy()->restart($current, $this->launcher);
        } finally {
            $this->assertFalse($current->stopping);
            $this->assertSame([$current], array_values($this->launcher->servers()));
        }
    }

    public function testServerArgumentsAndEnvironment(): void
    {
        $this->assertSame('', $this->strategy()->serverArguments(9501));
        $this->assertSame(['WATCHDOG_REUSE_PORT' => '9501'], $this->strategy()->serverEnvironment(9501));
    }

    public function testPreflightRefusesWebSocketServers(): void
    {
        $strategy = $this->strategy(servers: [
            ['name' => 'http', 'type' => ServerInterface::SERVER_HTTP],
            ['name' => 'reverb', 'type' => ServerInterface::SERVER_WEBSOCKET],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('The reuse_port strategy does not support WebSocket servers [reverb]. Use the backup_port strategy instead.');

        $strategy->preflight();
    }

    public function testPreflightRefusesOtherSystemsThanLinux(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('The reuse_port strategy requires Linux; Swoole cannot bind two servers to the same port on Darwin. Use the backup_port strategy instead.');

        $this->strategy(osFamily: 'Darwin')->preflight();
    }

    public function testPreflightWarnsWhenQueuedConnectionsAreNotMigrated(): void
    {
        file_put_contents($this->temporaryPath('tcp_migrate_req'), "0\n");

        $this->strategy(tcpMigrateReqPath: $this->temporaryPath('tcp_migrate_req'))->preflight();

        $this->assertSame(
            ['net.ipv4.tcp_migrate_req is 0: connections queued on a stopping server are reset. Set it to 1 (Linux 5.14+).'],
            $this->logger->messages('warning')
        );
    }

    public function testPreflightIsSilentWhenQueuedConnectionsAreMigrated(): void
    {
        file_put_contents($this->temporaryPath('tcp_migrate_req'), "1\n");

        $this->strategy(tcpMigrateReqPath: $this->temporaryPath('tcp_migrate_req'))->preflight();
        $this->strategy(tcpMigrateReqPath: $this->temporaryPath('missing'))->preflight();

        $this->assertSame([], $this->logger->messages());
    }

    protected function strategy(array $servers = [], string $osFamily = 'Linux', string $tcpMigrateReqPath = '/nonexistent'): ReusePortStrategy
    {
        return new ReusePortStrategy(
            $this->watchdogConfig(['strategy' => 'reuse_port', 'servers' => $servers]),
            $this->logger,
            $osFamily,
            $tcpMigrateReqPath
        );
    }
}
