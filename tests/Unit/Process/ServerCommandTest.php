<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Tests\Unit\Process;

use Dotenv\Exception\InvalidFileException;
use Hypervel\Testing\UnitTestCase;
use UniverseTech\Watchdog\Process\ServerCommand;
use UniverseTech\Watchdog\Strategy\BackupPortStrategy;
use UniverseTech\Watchdog\Strategy\ReusePortStrategy;
use UniverseTech\Watchdog\Tests\CreatesWatchdogConfig;
use UniverseTech\Watchdog\Tests\Fakes\InMemoryLogger;
use UniverseTech\Watchdog\Tests\UsesTemporaryDirectory;

class ServerCommandTest extends UnitTestCase
{
    use CreatesWatchdogConfig;
    use UsesTemporaryDirectory;

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();

        parent::tearDown();
    }

    public function testBackupPortCommandPassesThePort(): void
    {
        $command = $this->command(['php' => "'/usr/bin/php'", 'artisan' => "'artisan'"]);

        $this->assertSame("exec '/usr/bin/php' 'artisan' serve --port=9502", $command->command(9502));
    }

    public function testReusePortCommandLeavesThePortToTheProvider(): void
    {
        $command = $this->command(['startCommand' => 'custom:serve'], reusePort: true);

        $this->assertSame('exec php artisan custom:serve', $command->command(9501));
    }

    public function testEnvironmentWithoutOverloadIgnoresTheEnvFile(): void
    {
        file_put_contents($this->temporaryPath('.env'), "FOO BAR=baz\n");

        $this->assertSame(
            ['FORCE_COLOR' => 'true', 'TERM' => 'xterm-256color', 'HTTP_SERVER_PORT' => '9502'],
            $this->command()->environment(9502)
        );
    }

    public function testReusePortEnvironmentCarriesThePort(): void
    {
        $environment = $this->command(reusePort: true)->environment(9501);

        $this->assertSame('9501', $environment['WATCHDOG_REUSE_PORT']);
        $this->assertSame('9501', $environment['HTTP_SERVER_PORT']);
    }

    public function testOverloadRemovesEnvFileKeysSoTheServerReadsTheFile(): void
    {
        file_put_contents($this->temporaryPath('.env'), "APP_BUILD=2\nHTTP_SERVER_PORT=1234\n");

        $environment = $this->command(['envOverload' => true])->environment(9501);

        $this->assertFalse($environment['APP_BUILD']);
        $this->assertSame('9501', $environment['HTTP_SERVER_PORT'], 'The watchdog decides the port.');
    }

    public function testOverloadRemovesKeysDeletedFromTheEnvFileSinceStartup(): void
    {
        file_put_contents($this->temporaryPath('.env'), "REMOVED=1\n");
        $command = $this->command(['envOverload' => true]);

        file_put_contents($this->temporaryPath('.env'), "ADDED=1\n");
        $environment = $command->environment(9501);

        $this->assertFalse($environment['REMOVED'], 'The watchdog still holds the startup value.');
        $this->assertFalse($environment['ADDED']);
    }

    public function testOverloadWithoutEnvFile(): void
    {
        $this->assertCount(3, $this->command(['envOverload' => true])->environment(9501));
    }

    public function testOverloadFailsOnInvalidEnvFile(): void
    {
        file_put_contents($this->temporaryPath('.env'), "FOO BAR=baz\n");

        $this->expectException(InvalidFileException::class);

        $this->command(['envOverload' => true])->environment(9501);
    }

    protected function command(array $config = [], bool $reusePort = false): ServerCommand
    {
        $config = $this->watchdogConfig($config);
        $strategy = $reusePort
            ? new ReusePortStrategy($config, new InMemoryLogger())
            : new BackupPortStrategy($config, new InMemoryLogger());

        return new ServerCommand($config, $strategy, $this->temporaryPath('.env'));
    }
}
