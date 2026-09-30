<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Tests\Unit\Process;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use UniverseTech\Watchdog\EventChannel;
use UniverseTech\Watchdog\PidFile\ServerPidFile;
use UniverseTech\Watchdog\Process\ProcessServerLauncher;
use UniverseTech\Watchdog\Process\ServerCommand;
use UniverseTech\Watchdog\Strategy\BackupPortStrategy;
use UniverseTech\Watchdog\Tests\CreatesWatchdogConfig;
use UniverseTech\Watchdog\Tests\Fakes\InMemoryLogger;
use UniverseTech\Watchdog\Tests\TestCase;

/**
 * Runs tests/Fixtures/server.php as a real child process.
 */
#[Group('process')]
class ProcessServerLauncherTest extends TestCase
{
    use CreatesWatchdogConfig;

    protected int $port;

    protected EventChannel $events;

    protected InMemoryLogger $logger;

    protected BufferedOutput $output;

    protected ProcessServerLauncher $launcher;

    protected function setUp(): void
    {
        parent::setUp();

        // Symfony Process only passes on variables that are also in $_SERVER.
        putenv('WATCHDOG_TEST_STATE_DIR=' . $this->temporaryPath());
        $_SERVER['WATCHDOG_TEST_STATE_DIR'] = $this->temporaryPath();
        $this->port = $this->freePort();
        $this->events = new EventChannel();
        $this->logger = new InMemoryLogger();
        $this->output = new BufferedOutput();

        $config = $this->watchdogConfig([
            'php' => escapeshellarg(PHP_BINARY),
            'artisan' => escapeshellarg(dirname(__DIR__, 2) . '/Fixtures/server.php'),
            'serverPidFile' => $this->temporaryPath('server.pid'),
            'mainPort' => $this->port,
            'portTimeout' => 1,
            'timeout' => 2,
        ]);
        $this->launcher = new ProcessServerLauncher(
            $config,
            new ServerCommand($config, new BackupPortStrategy($config, $this->logger), $this->temporaryPath('.env')),
            new ServerPidFile($this->temporaryPath('server.pid'), new Filesystem()),
            $this->events,
            $this->logger,
            $this->output
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->launcher->servers() as $server) {
            $this->launcher->stop($server);
        }
        $this->events->close();

        foreach (glob($this->temporaryPath('pids') . '/*') ?: [] as $file) {
            @posix_kill((int) basename($file), SIGKILL);
            unlink($file);
        }
        @rmdir($this->temporaryPath('pids'));
        putenv('WATCHDOG_TEST_STATE_DIR');
        unset($_SERVER['WATCHDOG_TEST_STATE_DIR']);

        parent::tearDown();
    }

    public function testStartsAndStopsAServer(): void
    {
        $server = $this->launcher->start($this->port);

        $this->assertTrue($server->ready);
        $this->assertSame($server->process->id(), $server->pid, 'The exec\'ed server is the spawned process.');
        $this->assertSame((string) $server->pid, file_get_contents($this->temporaryPath('server.pid')));
        $this->assertTrue($this->launcher->isLive($server));
        $this->assertContains("Server pid: {$server->pid} started successfully. [{$this->port}]", $this->logger->messages());

        $this->launcher->stop($server);

        $this->assertTrue($server->exited);
        $this->assertFalse($this->launcher->isLive($server));
        $this->assertContains("Server [{$this->port}] (pid: [{$server->pid}]) is stopped.", $this->logger->messages());
        // Forwarded by the server's coroutine, which may lag behind start(); it has drained the
        // output once the server exited.
        $this->assertStringContainsString("[fixture] master {$server->pid} listening", $this->output->fetch());
    }

    public function testServerCrashingOnBootFailsTheStart(): void
    {
        touch($this->temporaryPath("crash-on-boot-{$this->port}"));

        try {
            $this->launcher->start($this->port);
            $this->fail('The start should fail.');
        } catch (RuntimeException $e) {
            $this->assertSame("Failed to start server. [{$this->port}]", $e->getMessage());
        }

        $this->assertSame([], $this->launcher->servers());
    }

    public function testBusyPortFailsTheStartWithoutSpawning(): void
    {
        $holder = stream_socket_server("tcp://127.0.0.1:{$this->port}");

        try {
            $this->expectException(RuntimeException::class);

            $this->launcher->start($this->port);
        } finally {
            fclose($holder);
            $this->assertContains("Port [{$this->port}] is still in use, waiting...", $this->logger->messages('warning'));
            $this->assertContains("Port [{$this->port}] is not available.", $this->logger->messages('error'));
            $this->assertFileDoesNotExist($this->temporaryPath('ports.log'));
        }
    }

    public function testServerIgnoringSigtermIsKilled(): void
    {
        touch($this->temporaryPath("ignore-sigterm-{$this->port}"));
        $server = $this->launcher->start($this->port);

        $this->launcher->stop($server);

        $this->assertTrue($server->exited);
        $this->assertContains("Server [{$this->port}] did not exit after SIGTERM, sending SIGKILL...", $this->logger->messages('warning'));
    }

    public function testExitAfterStartIsReported(): void
    {
        $server = $this->launcher->start($this->port);
        // The killed master leaves its manager and worker running, so tearDown() must know them.
        $this->waitUntilPidsRecorded(3);

        posix_kill($server->pid, SIGKILL);

        $this->assertSame($server, $this->events->next(5));
        $this->assertContains("Server stopped unexpectedly. [{$this->port}]", $this->logger->messages('error'));
    }

    public function testSpawnsTheServerCommandWithItsEnvironment(): void
    {
        Process::fake();

        try {
            $this->launcher->start($this->port);
        } catch (RuntimeException) {
            // A faked process exits at once, so the start itself fails.
        }

        Process::assertRan(function ($process): bool {
            $php = escapeshellarg(PHP_BINARY);

            return str_starts_with($process->command, "exec {$php} ")
                && str_ends_with($process->command, " serve --port={$this->port}")
                && $process->environment['HTTP_SERVER_PORT'] === (string) $this->port;
        });
    }

    protected function waitUntilPidsRecorded(int $count): void
    {
        $deadline = microtime(true) + 5;
        while (count(glob($this->temporaryPath('pids') . '/*') ?: []) < $count) {
            if (microtime(true) > $deadline) {
                $this->fail("The fixture did not record {$count} pids in time.");
            }

            usleep(10000);
        }
    }

    protected function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    }
}
