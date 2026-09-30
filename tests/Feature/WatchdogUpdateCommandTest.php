<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Tests\Feature;

use Hypervel\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use UniverseTech\Watchdog\PidFile\WatchdogPidFile;
use UniverseTech\Watchdog\Tests\TestCase;

class WatchdogUpdateCommandTest extends TestCase
{
    protected WatchdogPidFile $pidFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('config')->set('watchdog.watchdog_pid_file', $this->temporaryPath('watchdog.pid'));
        $this->pidFile = new WatchdogPidFile($this->temporaryPath('watchdog.pid'), new Filesystem());
    }

    protected function tearDown(): void
    {
        $this->pidFile->release();

        parent::tearDown();
    }

    public function testFailsWithoutAPidFile(): void
    {
        $this->artisan('watchdog:update')
            ->expectsOutputToContain('Watchdog process is not running.')
            ->assertFailed();
    }

    public function testFailsWithAStalePidFile(): void
    {
        file_put_contents($this->temporaryPath('watchdog.pid'), (string) getmypid());

        $this->artisan('watchdog:update')
            ->expectsOutputToContain('Watchdog process is not running.')
            ->assertFailed();
    }

    public static function invalidPids(): array
    {
        return [
            'not a number' => ['garbage'],
            'zero' => ['0'],
            // posix_kill() would signal a whole process group.
            'negative' => ['-1'],
        ];
    }

    #[DataProvider('invalidPids')]
    public function testRefusesAnInvalidPid(string $content): void
    {
        $this->pidFile->acquire(1);
        file_put_contents($this->temporaryPath('watchdog.pid'), $content);

        $this->artisan('watchdog:update')
            ->expectsOutputToContain('Pid file is invalid.')
            ->assertFailed();
    }

    public function testSendsTheReloadSignal(): void
    {
        // Signal 0 only checks that the process exists, so the test process survives it.
        $this->app->make('config')->set('watchdog.reload_signal', 0);
        $this->pidFile->acquire(getmypid());

        $this->artisan('watchdog:update')
            ->expectsOutputToContain('Broadcast update signal to watchdog process [' . getmypid() . '] successfully.')
            ->assertSuccessful();
    }

    public function testReportsWhyTheSignalFailed(): void
    {
        $this->app->make('config')->set('watchdog.reload_signal', 0);
        $this->pidFile->acquire(99999999);

        $this->artisan('watchdog:update')
            ->expectsOutputToContain('Broadcast update signal to watchdog process [99999999] failed:')
            ->assertFailed();
    }
}
