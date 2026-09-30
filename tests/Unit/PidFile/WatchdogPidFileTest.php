<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Tests\Unit\PidFile;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testing\UnitTestCase;
use RuntimeException;
use UniverseTech\Watchdog\PidFile\WatchdogPidFile;
use UniverseTech\Watchdog\Tests\UsesTemporaryDirectory;

class WatchdogPidFileTest extends UnitTestCase
{
    use UsesTemporaryDirectory;

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();

        parent::tearDown();
    }

    public function testAcquireWritesThePidAndHoldsTheLock(): void
    {
        $pidFile = $this->pidFile();

        $pidFile->acquire(4321);

        try {
            $other = $this->pidFile();
            $this->assertTrue($other->isLocked());
            $this->assertSame(4321, $other->readPid());
        } finally {
            $pidFile->release();
        }
    }

    public function testSecondAcquireIsRefused(): void
    {
        $first = $this->pidFile();
        $first->acquire(1);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageIs("Another watchdog is already running [{$this->temporaryPath('watchdog.pid')}].");

            $this->pidFile()->acquire(2);
        } finally {
            $first->release();
        }
    }

    public function testReleaseDeletesTheFileAndUnlocks(): void
    {
        $pidFile = $this->pidFile();
        $pidFile->acquire(1);

        $pidFile->release();

        $this->assertFileDoesNotExist($this->temporaryPath('watchdog.pid'));
        $this->assertFalse($this->pidFile()->isLocked());
    }

    public function testReleaseWithoutAcquireDoesNothing(): void
    {
        file_put_contents($this->temporaryPath('watchdog.pid'), '1');

        $this->pidFile()->release();

        $this->assertFileExists($this->temporaryPath('watchdog.pid'));
    }

    public function testMissingFileIsNotLocked(): void
    {
        $this->assertFalse($this->pidFile()->isLocked());
    }

    public function testStaleFileIsNotLocked(): void
    {
        file_put_contents($this->temporaryPath('watchdog.pid'), (string) getmypid());

        $this->assertFalse($this->pidFile()->isLocked());
    }

    public function testReadPidOfAMissingFileIsNull(): void
    {
        $this->assertNull($this->pidFile()->readPid());
    }

    protected function pidFile(): WatchdogPidFile
    {
        return new WatchdogPidFile($this->temporaryPath('watchdog.pid'), new Filesystem());
    }
}
