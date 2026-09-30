<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Tests\Unit\PidFile;

use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testing\UnitTestCase;
use RuntimeException;
use UniverseTech\Watchdog\PidFile\ServerPidFile;
use UniverseTech\Watchdog\Tests\UsesTemporaryDirectory;

class ServerPidFileTest extends UnitTestCase
{
    use UsesTemporaryDirectory;

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();

        parent::tearDown();
    }

    public function testReadReturnsNullWithoutAFile(): void
    {
        $this->assertNull($this->pidFile()->read());
    }

    public function testWriteThenRead(): void
    {
        $this->pidFile()->write(1234);

        $this->assertSame(1234, $this->pidFile()->read());
        $this->assertSame('1234', file_get_contents($this->temporaryPath('server.pid')));
    }

    public function testReadReturnsNullWhenTheFileVanishesWhileReading(): void
    {
        $filesystem = new class extends Filesystem {
            public function get(string $path, bool $lock = false): string
            {
                throw new FileNotFoundException("Unable to read file at path {$path}.");
            }
        };

        $this->assertNull((new ServerPidFile($this->temporaryPath('server.pid'), $filesystem))->read());
    }

    public function testWriteFailureThrows(): void
    {
        $path = $this->temporaryPath('missing/server.pid');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Failed to write server pid file [{$path}].");

        (new ServerPidFile($path, new Filesystem()))->write(1);
    }

    public function testClearDeletesTheFileAndToleratesAMissingOne(): void
    {
        $this->pidFile()->write(1);

        $this->pidFile()->clear();
        $this->pidFile()->clear();

        $this->assertFileDoesNotExist($this->temporaryPath('server.pid'));
    }

    protected function pidFile(): ServerPidFile
    {
        return new ServerPidFile($this->temporaryPath('server.pid'), new Filesystem());
    }
}
