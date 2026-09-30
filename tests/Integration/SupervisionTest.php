<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Large;

#[Group('integration')]
#[Large]
class SupervisionTest extends IntegrationTestCase
{
    public function testServerCrashStopsTheWatchdogWithFailure(): void
    {
        [$watchdog, $serverPid] = $this->startServing();

        posix_kill($serverPid, SIGKILL);

        $this->assertSame(1, $this->waitForExit($watchdog));
        $this->assertStringContainsString("Server stopped unexpectedly. [{$this->mainPort}]", $this->outputOf($watchdog));

        // Only Linux stops the Swoole manager and workers when their master is killed; elsewhere
        // they are orphaned, and the watchdog only knows the master pid.
        if (PHP_OS_FAMILY === 'Linux') {
            $this->assertNoLeftoverProcesses();
        }
    }

    public function testSigintStopsTheWatchdogAndItsServer(): void
    {
        [$watchdog] = $this->startServing();

        $this->signal($watchdog, SIGINT);

        $this->assertSame(0, $this->waitForExit($watchdog));
        $this->assertNoLeftoverProcesses();
    }

    public function testBusyMainPortFailsStartupWithoutSpawningAServer(): void
    {
        $holder = stream_socket_server("tcp://127.0.0.1:{$this->mainPort}");

        try {
            $watchdog = $this->startWatchdog();

            $this->assertSame(1, $this->waitForExit($watchdog));
            $this->assertStringContainsString("Port [{$this->mainPort}] is not available.", $this->outputOf($watchdog));
            $this->assertSame([], $this->startedPorts());
        } finally {
            fclose($holder);
        }
    }

    public function testSecondWatchdogIsRefused(): void
    {
        [$watchdog, $serverPid] = $this->startServing();

        $second = $this->startWatchdog();

        $this->assertSame(1, $this->waitForExit($second));
        $this->assertStringContainsString('Another watchdog is already running', $this->outputOf($second));
        $this->assertTrue($watchdog->isRunning());
        $this->assertSame($serverPid, $this->request($this->mainPort)['pid'] ?? null);
    }

    public function testInvalidEnvFileFailsStartup(): void
    {
        file_put_contents($this->stateDir . '/.env', "FOO BAR=baz\n");

        $watchdog = $this->startWatchdog();

        $this->assertSame(1, $this->waitForExit($watchdog));
        $this->assertStringContainsString('Failed to parse dotenv file', $this->outputOf($watchdog));
        $this->assertSame([], $this->startedPorts());
    }

    public function testEnvFileIsReloadedOnRestart(): void
    {
        file_put_contents($this->stateDir . '/.env', "APP_BUILD=1\n");
        [$watchdog] = $this->startServing();

        file_put_contents($this->stateDir . '/.env', "FOO BAR=baz\n");
        $this->signal($watchdog, SIGUSR2);

        $this->waitForOutput($watchdog, 'Restart aborted');
        $this->assertStringContainsString('Failed to parse dotenv file', $this->outputOf($watchdog));
        $this->assertTrue($watchdog->isRunning());
    }
}
