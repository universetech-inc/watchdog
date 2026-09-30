<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;

#[Group('integration')]
#[Large]
class ReusePortRestartTest extends IntegrationTestCase
{
    protected const array REUSE_PORT = ['WATCHDOG_STRATEGY' => 'reuse_port'];

    #[RequiresOperatingSystemFamily('Linux')]
    public function testRestartStartsTheNewServerOnTheSamePort(): void
    {
        [$watchdog, $originalPid] = $this->startServing(self::REUSE_PORT);

        $this->signal($watchdog, SIGUSR2);
        $this->waitForOutput($watchdog, 'Server restarted successfully.');

        $this->assertStringContainsString('Starting new server on the same port...', $this->outputOf($watchdog));
        $this->assertSame([$this->mainPort, $this->mainPort], $this->startedPorts());

        $response = $this->waitUntilServing($this->mainPort);
        $this->assertNotSame($originalPid, $response['pid']);
        $this->assertSame($response['pid'], $this->serverPid(), 'The server pid file must name the new server.');
        $this->assertFalse($this->isAlive($originalPid));

        $this->signal($watchdog, SIGTERM);
        $this->assertSame(0, $this->waitForExit($watchdog));
        $this->assertNoLeftoverProcesses();
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function testNewServerCrashingOnBootAbortsTheRestart(): void
    {
        [$watchdog, $originalPid] = $this->startServing(self::REUSE_PORT);
        $this->flag("crash-on-boot-{$this->mainPort}");

        $this->signal($watchdog, SIGUSR2);
        $this->waitForOutput($watchdog, "Restart aborted, the original server (pid: [{$originalPid}]) keeps running.");

        $this->assertSame($originalPid, $this->request($this->mainPort)['pid'] ?? null);
        $this->assertSame($originalPid, $this->serverPid(), 'The server pid file must name the original server again.');
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function testPortKeepsAcceptingConnectionsDuringTheRestart(): void
    {
        [$watchdog] = $this->startServing(self::REUSE_PORT);
        $this->flag('boot-delay', '300');

        $this->signal($watchdog, SIGUSR2);
        $refused = 0;
        $requests = 0;
        while (substr_count($this->outputOf($watchdog), 'Server restarted successfully.') < 1) {
            $socket = @stream_socket_client("tcp://127.0.0.1:{$this->mainPort}", $errno, $errstr, 1);
            $socket === false ? $refused++ : fclose($socket);
            $requests++;
            $this->assertTrue($watchdog->isRunning(), $this->outputOf($watchdog));
        }

        $this->assertGreaterThan(0, $requests);
        $this->assertSame(0, $refused, 'Connections were refused while the servers were handed over.');
    }

    #[RequiresOperatingSystemFamily('Darwin')]
    public function testRefusesToStartOutsideLinux(): void
    {
        $watchdog = $this->startWatchdog(self::REUSE_PORT);

        $this->assertSame(1, $this->waitForExit($watchdog));
        $this->assertStringContainsString('The reuse_port strategy requires Linux', $this->outputOf($watchdog));
        $this->assertSame([], $this->startedPorts());
        $this->assertFileDoesNotExist($this->stateDir . '/watchdog.pid');
    }
}
