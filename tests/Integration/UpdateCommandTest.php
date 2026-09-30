<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Large;
use Symfony\Component\Process\Process;

#[Group('integration')]
#[Large]
class UpdateCommandTest extends IntegrationTestCase
{
    public function testUpdateIgnoresAStalePidFileNamingAnUnrelatedProcess(): void
    {
        $victim = new Process(['sleep', '30']);
        $victim->start();
        $this->processes[] = $victim;
        file_put_contents($this->stateDir . '/watchdog.pid', (string) $victim->getPid());

        $update = $this->runUpdate();

        $this->assertSame(1, $update->getExitCode());
        $this->assertStringContainsString('Watchdog process is not running.', $this->outputOf($update));
        $this->assertTrue($victim->isRunning(), 'The reload signal must not reach an unrelated process.');
    }

    public function testServersDoNotInheritTheWatchdogLock(): void
    {
        [$watchdog] = $this->startServing();

        posix_kill($watchdog->getPid(), SIGKILL);
        $this->waitForExit($watchdog);
        $this->assertNotNull($this->request($this->mainPort), 'The orphaned server keeps running.');

        $update = $this->runUpdate();

        $this->assertSame(1, $update->getExitCode());
        $this->assertStringContainsString('Watchdog process is not running.', $this->outputOf($update));
    }
}
