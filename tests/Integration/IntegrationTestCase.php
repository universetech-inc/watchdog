<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Runs the real watchdog in a subprocess against tests/Fixtures/server.php.
 *
 * Tests stay outside Swoole coroutines: they spawn processes and send them signals, which
 * the coroutine test runner cannot do safely.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected const float TIMEOUT = 20.0;

    protected const int POLL_INTERVAL = 100000;

    protected string $stateDir;

    protected int $mainPort;

    protected int $backupPort;

    /**
     * @var list<Process>
     */
    protected array $processes = [];

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/watchdog-it-' . bin2hex(random_bytes(4));
        mkdir($this->stateDir);

        $this->mainPort = $this->freePort();
        do {
            $this->backupPort = $this->freePort();
        } while ($this->backupPort === $this->mainPort);
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                posix_kill($process->getPid(), SIGKILL);
            }
        }

        foreach ($this->recordedPids() as $pid) {
            @posix_kill($pid, SIGKILL);
        }

        $this->removeDirectory($this->stateDir);
    }

    protected function startWatchdog(array $env = []): Process
    {
        $process = $this->process('watchdog:start', $env);
        $process->start();

        return $process;
    }

    protected function runUpdate(array $env = []): Process
    {
        $process = $this->process('watchdog:update', $env);
        $process->run();

        return $process;
    }

    /**
     * Start the watchdog and wait until it supervises a server that serves the main port.
     *
     * Waiting for the log line matters: the server answers requests slightly before the
     * watchdog marks it ready, and an exit before that counts as a failed start.
     *
     * @return array{Process, int} the watchdog and the pid of the server it started
     */
    protected function startServing(array $env = []): array
    {
        $watchdog = $this->startWatchdog($env);
        $this->waitForOutput($watchdog, 'started successfully.');
        $response = $this->waitUntilServing($this->mainPort, $watchdog);

        return [$watchdog, $response['pid']];
    }

    protected function process(string $command, array $env = []): Process
    {
        $process = new Process(
            [PHP_BINARY, dirname(__DIR__) . '/Fixtures/watchdog.php', $command],
            null,
            array_merge([
                'WATCHDOG_TEST_STATE_DIR' => $this->stateDir,
                'WATCHDOG_PID_FILE' => $this->stateDir . '/watchdog.pid',
                'WATCHDOG_MAIN_SERVER_PORT' => (string) $this->mainPort,
                'WATCHDOG_BACKUP_SERVER_PORT' => (string) $this->backupPort,
                'WATCHDOG_TIMEOUT' => '3',
                'WATCHDOG_PORT_TIMEOUT' => '2',
                'WATCHDOG_STRATEGY' => 'backup_port',
            ], $env),
        );
        $process->setTimeout(null);
        $this->processes[] = $process;

        return $process;
    }

    protected function signal(Process $process, int $signal): void
    {
        posix_kill($process->getPid(), $signal);
    }

    protected function outputOf(Process $process): string
    {
        return $process->getOutput() . $process->getErrorOutput();
    }

    protected function waitForOutput(Process $process, string $needle, int $count = 1, float $timeout = self::TIMEOUT): void
    {
        $deadline = microtime(true) + $timeout;
        while (substr_count($this->outputOf($process), $needle) < $count) {
            if (microtime(true) > $deadline || ! $process->isRunning()) {
                $this->fail("Expected [{$needle}] x{$count} in the watchdog output:\n" . $this->outputOf($process));
            }

            usleep(self::POLL_INTERVAL);
        }
    }

    protected function waitForExit(Process $process, float $timeout = self::TIMEOUT): int
    {
        $deadline = microtime(true) + $timeout;
        while ($process->isRunning()) {
            if (microtime(true) > $deadline) {
                $this->fail("The watchdog did not exit in time:\n" . $this->outputOf($process));
            }

            usleep(self::POLL_INTERVAL);
        }

        return (int) $process->getExitCode();
    }

    /**
     * @return array{pid: int, port: int}
     */
    protected function waitUntilServing(int $port, ?Process $watchdog = null, float $timeout = self::TIMEOUT): array
    {
        $deadline = microtime(true) + $timeout;
        while (($response = $this->request($port)) === null) {
            if (microtime(true) > $deadline || ($watchdog && ! $watchdog->isRunning())) {
                $this->fail("Port [{$port}] is not serving:\n" . ($watchdog ? $this->outputOf($watchdog) : ''));
            }

            usleep(self::POLL_INTERVAL);
        }

        return $response;
    }

    /**
     * @return null|array{pid: int, port: int}
     */
    protected function request(int $port): ?array
    {
        $context = stream_context_create(['http' => ['timeout' => 1]]);
        $body = @file_get_contents("http://127.0.0.1:{$port}/", false, $context);

        return $body === false ? null : json_decode($body, true);
    }

    protected function serverPid(): ?int
    {
        $file = $this->stateDir . '/server.pid';

        return is_file($file) ? (int) file_get_contents($file) : null;
    }

    protected function flag(string $name, string $content = ''): void
    {
        file_put_contents($this->stateDir . '/' . $name, $content);
    }

    /**
     * @return list<int> ports the fixture server was started on, in order
     */
    protected function startedPorts(): array
    {
        $log = $this->stateDir . '/ports.log';

        return is_file($log) ? array_map('intval', file($log, FILE_IGNORE_NEW_LINES)) : [];
    }

    protected function assertNoLeftoverProcesses(float $timeout = 5.0): void
    {
        $deadline = microtime(true) + $timeout;
        while (($alive = array_values(array_filter($this->recordedPids(), $this->isAlive(...)))) !== []) {
            if (microtime(true) > $deadline) {
                $this->fail('Server processes left running: ' . implode(', ', $alive));
            }

            usleep(self::POLL_INTERVAL);
        }

        $this->addToAssertionCount(1);
    }

    /**
     * A zombie counts as dead: in a container without an init process, nothing reaps the
     * servers orphaned by a watchdog that has exited.
     */
    protected function isAlive(int $pid): bool
    {
        if (! posix_kill($pid, 0)) {
            return false;
        }

        $stat = @file_get_contents("/proc/{$pid}/stat");

        return $stat === false || ! preg_match('/^\d+ \(.*\) Z /', $stat);
    }

    /**
     * @return list<int>
     */
    protected function recordedPids(): array
    {
        $files = glob($this->stateDir . '/pids/*') ?: [];

        return array_map(static fn (string $file): int => (int) basename($file), $files);
    }

    protected function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    }

    protected function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
