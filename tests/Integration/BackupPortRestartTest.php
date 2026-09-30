<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Large;

#[Group('integration')]
#[Large]
class BackupPortRestartTest extends IntegrationTestCase
{
    public function testRestartReplacesTheServerAndShutsDownCleanly(): void
    {
        [$watchdog, $originalPid] = $this->startServing();

        $update = $this->runUpdate();
        $this->assertSame(0, $update->getExitCode(), $update->getOutput());
        $this->waitForOutput($watchdog, 'Server restarted successfully.');

        $response = $this->waitUntilServing($this->mainPort);
        $this->assertNotSame($originalPid, $response['pid']);
        $this->assertSame($response['pid'], $this->serverPid(), 'The server pid file must name the new server.');
        $this->assertSame([$this->mainPort, $this->backupPort, $this->mainPort], $this->startedPorts());

        $this->signal($watchdog, SIGTERM);
        $this->assertSame(0, $this->waitForExit($watchdog));
        $this->assertFileDoesNotExist($this->stateDir . '/watchdog.pid');
        $this->assertNoLeftoverProcesses();
    }

    public function testReloadSignalsDuringARestartAreMergedIntoOneMore(): void
    {
        [$watchdog] = $this->startServing();
        $this->flag('boot-delay', '500');

        $this->signal($watchdog, SIGUSR2);
        $this->waitForOutput($watchdog, 'Restarting server...');
        foreach (range(1, 3) as $ignored) {
            $this->signal($watchdog, SIGUSR2);
            usleep(50000);
        }

        $this->waitForOutput($watchdog, 'Server restarted successfully.', 2);
        usleep(2000000);

        $this->assertSame(2, substr_count($this->outputOf($watchdog), 'Restarting server...'));
        $this->assertStringNotContainsString('unexpectedly', $this->outputOf($watchdog));
        $this->assertTrue($watchdog->isRunning());
    }

    public function testBackupCrashingOnBootAbortsTheRestart(): void
    {
        [$watchdog, $originalPid] = $this->startServing();
        $this->flag("crash-on-boot-{$this->backupPort}");

        $this->signal($watchdog, SIGUSR2);
        $this->waitForOutput($watchdog, "Restart aborted, the original server (pid: [{$originalPid}]) keeps running.");

        $this->assertSame($originalPid, $this->request($this->mainPort)['pid'] ?? null);
        $this->assertSame($originalPid, $this->serverPid(), 'The server pid file must name the original server again.');
        $this->assertTrue($watchdog->isRunning());

        unlink($this->stateDir . "/crash-on-boot-{$this->backupPort}");
        $this->signal($watchdog, SIGUSR2);
        $this->waitForOutput($watchdog, 'Server restarted successfully.');
    }

    public function testBackupHangingOnBootIsStoppedAndTheRestartAborted(): void
    {
        [$watchdog, $originalPid] = $this->startServing();
        $this->flag("hang-on-boot-{$this->backupPort}");

        $this->signal($watchdog, SIGUSR2);
        $this->waitForOutput($watchdog, 'Restart aborted');

        $this->assertStringContainsString("Failed to start server. [{$this->backupPort}]", $this->outputOf($watchdog));
        $this->assertSame($originalPid, $this->request($this->mainPort)['pid'] ?? null);
        $this->assertSame($originalPid, $this->serverPid(), 'The server pid file must name the original server again.');

        $this->signal($watchdog, SIGTERM);
        $this->assertSame(0, $this->waitForExit($watchdog));
        $this->assertNoLeftoverProcesses();
    }

    public function testNewMainServerFailingAfterTheOriginalStoppedExitsWithFailure(): void
    {
        [$watchdog] = $this->startServing();
        $this->flag("crash-on-boot-{$this->mainPort}");

        $this->signal($watchdog, SIGUSR2);

        $this->assertSame(1, $this->waitForExit($watchdog));
        $this->assertFileDoesNotExist($this->stateDir . '/watchdog.pid');
        $this->assertNoLeftoverProcesses();
        $this->assertNull($this->request($this->backupPort));
    }
}
