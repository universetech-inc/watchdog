<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Tests\Unit\Strategy;

use Hypervel\Testing\UnitTestCase;
use RuntimeException;
use UniverseTech\Watchdog\Strategy\BackupPortStrategy;
use UniverseTech\Watchdog\Tests\CreatesWatchdogConfig;
use UniverseTech\Watchdog\Tests\Fakes\FakeServerLauncher;
use UniverseTech\Watchdog\Tests\Fakes\InMemoryLogger;

class BackupPortStrategyTest extends UnitTestCase
{
    use CreatesWatchdogConfig;

    protected FakeServerLauncher $launcher;

    protected InMemoryLogger $logger;

    protected BackupPortStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->launcher = new FakeServerLauncher();
        $this->logger = new InMemoryLogger();
        $this->strategy = new BackupPortStrategy($this->watchdogConfig(), $this->logger);
    }

    public function testRestartHandsTheMainPortOverThroughTheBackupPort(): void
    {
        $current = $this->launcher->start(9501);

        $next = $this->strategy->restart($current, $this->launcher);

        $this->assertSame(['start 9501', 'start 9502', 'stop 9501 (1000)', 'start 9501', 'stop 9502 (1001)'], $this->launcher->calls);
        $this->assertSame(9501, $next->port);
        $this->assertSame(1002, $next->pid);
        $this->assertSame([$next], array_values($this->launcher->servers()));
        $this->assertSame([
            'Starting new server...',
            'Stopping original server (pid: [1000])...',
            'Transferring server port...',
            'Stopping backup server (pid: [1001])...',
        ], $this->logger->messages());
    }

    public function testBackupFailingToStartLeavesTheOriginalServerAlone(): void
    {
        $current = $this->launcher->start(9501);
        $this->launcher->failStart[9502] = true;

        try {
            $this->strategy->restart($current, $this->launcher);
            $this->fail('The restart should fail.');
        } catch (RuntimeException $e) {
            $this->assertSame('Failed to start server. [9502]', $e->getMessage());
        }

        $this->assertFalse($current->stopping);
        $this->assertSame([$current], array_values($this->launcher->servers()));
    }

    public function testNewMainServerFailingLeavesOnlyTheBackupServer(): void
    {
        $current = $this->launcher->start(9501);
        $this->launcher->afterStart = function ($server): void {
            if ($server->port === 9502) {
                $this->launcher->failStart[9501] = true;
            }
        };

        $this->expectException(RuntimeException::class);

        try {
            $this->strategy->restart($current, $this->launcher);
        } finally {
            $this->assertTrue($current->exited);
            $this->assertSame([9502], array_map(fn ($server) => $server->port, array_values($this->launcher->servers())));
        }
    }

    public function testServerArgumentsAndEnvironment(): void
    {
        $this->assertSame('--port=9502', $this->strategy->serverArguments(9502));
        $this->assertSame([], $this->strategy->serverEnvironment(9502));
    }
}
