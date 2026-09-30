<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Process;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Support\Facades\Process;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Throwable;
use UniverseTech\Watchdog\EventChannel;
use UniverseTech\Watchdog\PidFile\ServerPidFile;
use UniverseTech\Watchdog\WatchdogConfig;

/**
 * Runs each server as a child process owned by its own coroutine.
 */
class ProcessServerLauncher implements ServerLauncher
{
    /**
     * Microseconds between checks while waiting for a server to start or stop.
     */
    protected const int POLL_INTERVAL = 100000;

    /**
     * Seconds to wait for a server to exit after SIGKILL.
     */
    protected const int KILL_TIMEOUT = 5;

    /**
     * @var array<int, ServerProcess>
     */
    protected array $servers = [];

    public function __construct(
        protected WatchdogConfig $config,
        protected ServerCommand $command,
        protected ServerPidFile $pidFile,
        protected EventChannel $events,
        protected LoggerInterface $logger,
        protected OutputInterface $output
    ) {
    }

    public function start(int $port, bool $sharesPort = false): ServerProcess
    {
        $environment = $this->command->environment($port);

        $this->pidFile->clear();

        $server = new ServerProcess($port, $sharesPort);
        $this->servers[spl_object_id($server)] = $server;

        Coroutine::create(fn () => $this->run($server, $environment));

        try {
            $server->pid = $this->waitStarted($server);
        } catch (Throwable $e) {
            // A half-started server would hold the port and outlive the watchdog.
            $this->stop($server);
            throw $e;
        }

        $server->ready = true;
        $this->logger->info("Server pid: {$server->pid} started successfully. [{$port}]");

        return $server;
    }

    /**
     * A server that ignores SIGTERM for `timeout` seconds is killed. Its worker processes
     * may then outlive it.
     */
    public function stop(ServerProcess $server): void
    {
        $server->stopping = true;

        if (! $this->signalAndWait($server, SIGTERM, $this->config->timeout)) {
            $this->logger->warning("Server [{$server->port}] did not exit after SIGTERM, sending SIGKILL...");

            if (! $this->signalAndWait($server, SIGKILL, self::KILL_TIMEOUT)) {
                throw new RuntimeException("Failed to stop server. [{$server->port}]");
            }
        }

        unset($this->servers[spl_object_id($server)]);
    }

    public function servers(): array
    {
        return $this->servers;
    }

    public function isLive(ServerProcess $server): bool
    {
        return isset($this->servers[spl_object_id($server)]);
    }

    /**
     * Run a server process until it exits. Executed in its own coroutine.
     */
    protected function run(ServerProcess $server, array $environment): void
    {
        try {
            $this->logger->info("Starting server [{$server->port}]...");

            if ((! $server->sharesPort && ! $this->waitPortAvailable($server)) || $server->stopping) {
                return;
            }

            $server->process = Process::forever()
                ->env($environment)
                ->start($this->command->command($server->port));

            // The watchdog may have given up on this server while it was being spawned.
            if ($server->stopping && $server->process->running()) {
                $server->process->signal(SIGTERM);
            }

            $server->process->wait(function ($type, $buffer) {
                $this->output->write($buffer);
            });
        } catch (ProcessSignaledException $e) {
            $this->logger->error($e->getMessage());
        } finally {
            $server->exited = true;

            // Exits during startup are reported by start().
            if ($server->ready && ! $server->stopping) {
                $this->logger->error("Server stopped unexpectedly. [{$server->port}]");
                $this->events->reportExit($server);
            }
        }
    }

    /**
     * The timeout starts once the process is spawned. Waiting for the port before that
     * is bounded by `port_timeout`.
     */
    protected function waitStarted(ServerProcess $server): int
    {
        $start = null;
        while (! $server->exited) {
            if ($server->process === null) {
                usleep(self::POLL_INTERVAL);
                continue;
            }

            $start ??= time();
            if ($server->process->running() && $pid = $this->pidFile->read()) {
                return $pid;
            }

            if ((time() - $start) > $this->config->timeout) {
                break;
            }

            usleep(self::POLL_INTERVAL);
        }

        throw new RuntimeException("Failed to start server. [{$server->port}]");
    }

    /**
     * Send a signal to the server once its pid is known, then wait for it to exit.
     */
    protected function signalAndWait(ServerProcess $server, int $signal, int $timeout): bool
    {
        $start = time();
        $pid = null;
        $hasWarned = false;
        while (! $server->exited) {
            if ((time() - $start) >= $timeout) {
                return false;
            }

            // Before the pid file is written, the process handle is the server itself
            // because the start command is exec'ed.
            if ($pid === null) {
                $pid = $server->pid ?? $server->process?->id();
                if ($pid !== null) {
                    posix_kill($pid, $signal);
                }
            }

            if ($pid !== null && ! $hasWarned && (time() - $start) >= 1) {
                $hasWarned = true;
                $this->logger->warning("Server [{$server->port}] is still alive, waiting...");
            }

            usleep(self::POLL_INTERVAL);
        }

        if ($pid !== null) {
            $this->logger->info("Server [{$server->port}] (pid: [{$pid}]) is stopped.");
        }

        return true;
    }

    protected function waitPortAvailable(ServerProcess $server): bool
    {
        $port = $server->port;
        $start = time();
        $hasWarned = false;
        while (! $server->stopping && (time() - $start) < $this->config->portTimeout) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
            if (is_resource($connection)) {
                fclose($connection);
                if (! $hasWarned) {
                    $hasWarned = true;
                    $this->logger->warning("Port [{$port}] is still in use, waiting...");
                }
                sleep(1);
                continue;
            }

            $this->logger->info("Port [{$port}] is available.");
            return true;
        }

        if (! $server->stopping) {
            $this->logger->error("Port [{$port}] is not available.");
        }

        return false;
    }
}
