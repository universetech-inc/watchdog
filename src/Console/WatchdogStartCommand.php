<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Console;

use Dotenv\Dotenv;
use Hypervel\Console\Application;
use Hypervel\Console\Command;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\Process;
use RuntimeException;
use Swoole\Process as SwooleProcess;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Throwable;
use UniverseTech\Watchdog\ServerProcess;

#[AsCommand(name: 'watchdog:start')]
class WatchdogStartCommand extends Command
{
    /**
     * Signals that stop the watchdog together with the servers it supervises.
     */
    protected const array TERMINATE_SIGNALS = [SIGTERM, SIGINT];

    /**
     * Seconds between event checks. The timeout also keeps a timer on the event loop,
     * so Swoole does not treat an idle watchdog as a coroutine deadlock.
     */
    protected const float EVENT_POLL_INTERVAL = 1.0;

    /**
     * Seconds a signal handler may wait to enqueue an event before dropping it.
     */
    protected const float EVENT_PUSH_TIMEOUT = 0.001;

    /**
     * Microseconds between checks while waiting for a server to start or stop.
     */
    protected const int SERVER_POLL_INTERVAL = 100000;

    /**
     * Seconds to wait for a server to exit after SIGKILL.
     */
    protected const int SERVER_KILL_TIMEOUT = 5;

    protected ?string $signature = 'watchdog:start';

    protected string $description = 'Start watchdog for servers.';

    /**
     * Carries `true` for a start/restart request, `false` to wake the loop for termination,
     * and a ServerProcess that exited unexpectedly.
     */
    protected ?Channel $channel = null;

    protected bool $terminating = false;

    protected bool $stopped = false;

    protected int $reloadSignal;

    protected string $watchdogPidFile;

    protected string $serverPidFile;

    /**
     * Handle holding an exclusive lock on the watchdog pid file while the watchdog runs.
     *
     * @var null|resource
     */
    protected $watchdogPidHandle = null;

    /**
     * The server on the main port that the watchdog supervises.
     */
    protected ?ServerProcess $current = null;

    /**
     * Every server started by the watchdog and not yet confirmed stopped, keyed by object id.
     *
     * @var array<int, ServerProcess>
     */
    protected array $servers = [];

    public function __construct(
        protected ApplicationContract $app,
        protected Repository $config,
        protected Filesystem $filesystem
    ) {
        parent::__construct();

        $this->reloadSignal = (int) $this->config->get('watchdog.reload_signal', SIGUSR2);
        $this->watchdogPidFile = $this->config->get('watchdog.watchdog_pid_file', $this->app->storagePath('framework/watchdog.pid'));
        $this->serverPidFile = $this->config->get('watchdog.server_pid_file')
            ?? $this->config->get('server.settings.pid_file', $this->app->storagePath('framework/hypervel.pid'));
    }

    public function handle(): int
    {
        try {
            $this->init();
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        while ($this->waitForRequest()) {
            try {
                if ($this->current === null) {
                    $this->current = $this->startServer($this->mainPort());
                } else {
                    $this->restartServer();
                }
            } catch (Throwable $e) {
                $this->error($e->getMessage());

                if (! $this->canRecover()) {
                    break;
                }

                $this->warn("Restart aborted, the original server (pid: [{$this->current->pid}]) keeps running.");
            }
        }

        $this->shutdown();

        return $this->terminating ? self::SUCCESS : self::FAILURE;
    }

    protected function init(): void
    {
        $this->channel = new Channel(1);
        $this->channel->push(true);

        $this->registerSignals();
        $this->writeWatchdogPidFile(posix_getpid());
    }

    /**
     * Wait for the next start/restart request.
     *
     * Returns false once the watchdog should stop: a supervised server stopped
     * unexpectedly or a terminate signal was received.
     */
    protected function waitForRequest(): bool
    {
        while (! $this->terminating) {
            $event = $this->channel->pop(self::EVENT_POLL_INTERVAL);
            if ($event === false && $this->channel->isTimeout()) {
                continue;
            }

            if ($event instanceof ServerProcess) {
                // A server that a restart has already replaced needs no action.
                if (isset($this->servers[spl_object_id($event)])) {
                    return false;
                }

                continue;
            }

            return $event === true && ! $this->terminating;
        }

        return false;
    }

    /**
     * A failure is recoverable only while the original server still serves the main
     * port and is the only server left, i.e. the backup server never came up.
     */
    protected function canRecover(): bool
    {
        return $this->current !== null
            && ! $this->current->stopping
            && ! $this->current->exited
            && count($this->servers) === 1;
    }

    /**
     * Handlers stay registered until the process exits, so signals arriving while a
     * restart is in progress are not lost. They do not keep the event loop alive.
     *
     * They are never unregistered: once Swoole has handled a signal, it swallows later
     * deliveries that have no callback instead of applying the default action.
     */
    protected function registerSignals(): void
    {
        $this->registerSignal($this->reloadSignal, function () {
            if ($this->terminating || $this->stopped) {
                return;
            }

            // A full channel means a restart is already pending.
            $this->channel->push(true, self::EVENT_PUSH_TIMEOUT);
        });

        foreach (self::TERMINATE_SIGNALS as $signal) {
            $this->registerSignal($signal, function (int $signo) {
                if ($this->terminating) {
                    return;
                }

                $this->terminating = true;
                $this->warn("Received signal [{$signo}], stopping watchdog...");

                // The event loop has already ended, but a server that failed to stop keeps
                // this process alive, so try again.
                if ($this->stopped) {
                    $this->stopServers();
                    return;
                }

                $this->channel->push(false, self::EVENT_PUSH_TIMEOUT);
            });
        }
    }

    protected function registerSignal(int $signal, callable $handler): void
    {
        if (! SwooleProcess::signal($signal, $handler)) {
            throw new RuntimeException("Failed to register handler for signal [{$signal}].");
        }
    }

    /**
     * Stop every server, whatever the reason the loop ended. A server left running would
     * keep this process alive unsupervised and hold its port against the next watchdog.
     */
    protected function shutdown(): void
    {
        $this->stopped = true;

        $this->stopServers();

        // Release server coroutines still blocked on pushing an exit event.
        $this->channel->close();
        $this->removeWatchdogPidFile();
    }

    protected function stopServers(): void
    {
        foreach ($this->servers as $server) {
            try {
                $this->stopServer($server);
            } catch (Throwable $e) {
                $this->error($e->getMessage());
            }
        }
    }

    protected function startServer(int $port): ServerProcess
    {
        $env = [
            'FORCE_COLOR' => 'true',
            'TERM' => 'xterm-256color',
            'HTTP_SERVER_PORT' => (string) $port,
        ];

        if ($this->config->get('watchdog.env_overload', true)) {
            $env = array_merge(
                array_fill_keys(array_keys($this->loadDotEnv()), false),
                $env
            );
        }

        $this->clearServerPidFile();

        $server = new ServerProcess($port);
        $this->servers[spl_object_id($server)] = $server;

        Coroutine::create(fn () => $this->runServer($server, $env));

        try {
            $server->pid = $this->waitServerStarted($server);
        } catch (Throwable $e) {
            // A half-started server would hold the port and outlive the watchdog.
            $this->stopServer($server);
            throw $e;
        }

        $server->ready = true;
        $this->info("Server pid: {$server->pid} started successfully. [{$port}]");

        return $server;
    }

    /**
     * Run a server process until it exits. Executed in its own coroutine.
     */
    protected function runServer(ServerProcess $server, array $env): void
    {
        try {
            $this->info("Starting server [{$server->port}]...");

            if (! $this->waitPortAvailable($server) || $server->stopping) {
                return;
            }

            $server->process = Process::forever()
                ->env($env)
                ->start($this->getServerStartCommand());

            // The watchdog may have given up on this server while it was being spawned.
            if ($server->stopping && $server->process->running()) {
                $server->process->signal(SIGTERM);
            }

            $server->process->wait(function ($type, $buffer) {
                $this->output->write($buffer);
            });
        } catch (ProcessSignaledException $e) {
            $this->error($e->getMessage());
        } finally {
            $server->exited = true;

            // Exits during startup are reported by startServer().
            if ($server->ready && ! $server->stopping) {
                $this->error("Server stopped unexpectedly. [{$server->port}]");
                $this->channel->push($server);
            }
        }
    }

    /**
     * The timeout starts once the process is spawned. Waiting for the port before that
     * is bounded by `port_timeout`.
     */
    protected function waitServerStarted(ServerProcess $server): int
    {
        $timeout = (int) $this->config->get('watchdog.timeout', 30);
        $start = null;
        while (! $server->exited) {
            if ($server->process === null) {
                usleep(self::SERVER_POLL_INTERVAL);
                continue;
            }

            $start ??= time();
            if ($server->process->running() && $pid = $this->getServerPid()) {
                return $pid;
            }

            if ((time() - $start) > $timeout) {
                break;
            }

            usleep(self::SERVER_POLL_INTERVAL);
        }

        throw new RuntimeException("Failed to start server. [{$server->port}]");
    }

    protected function restartServer(): void
    {
        $this->info('Restarting server...');

        // Until the backup server is up, a failure leaves the original server untouched.
        $this->info('Starting new server...');
        $backup = $this->startServer((int) $this->config->get('watchdog.server_ports.backup', 9502));

        $this->info("Stopping original server (pid: [{$this->current->pid}])...");
        $this->stopServer($this->current);

        $this->info('Transferring server port...');
        $this->current = $this->startServer($this->mainPort());

        $this->info("Stopping backup server (pid: [{$backup->pid}])...");
        $this->stopServer($backup);

        // Both servers share one pid file and Swoole unlinks it on shutdown regardless
        // of its content, so stopping the backup server removes the current server's pid.
        $this->writeServerPidFile($this->current->pid);

        $this->info('Server restarted successfully.');
    }

    /**
     * Stop a server and wait until the coroutine that owns its process has seen it exit.
     *
     * A server that ignores SIGTERM for `timeout` seconds is killed. Its worker processes
     * may then outlive it.
     */
    protected function stopServer(ServerProcess $server): void
    {
        $server->stopping = true;

        if (! $this->signalAndWait($server, SIGTERM, (int) $this->config->get('watchdog.timeout', 30))) {
            $this->warn("Server [{$server->port}] did not exit after SIGTERM, sending SIGKILL...");

            if (! $this->signalAndWait($server, SIGKILL, self::SERVER_KILL_TIMEOUT)) {
                throw new RuntimeException("Failed to stop server. [{$server->port}]");
            }
        }

        unset($this->servers[spl_object_id($server)]);
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
                $this->warn("Server [{$server->port}] is still alive, waiting...");
            }

            usleep(self::SERVER_POLL_INTERVAL);
        }

        if ($pid !== null) {
            $this->info("Server [{$server->port}] (pid: [{$pid}]) is stopped.");
        }

        return true;
    }

    protected function waitPortAvailable(ServerProcess $server): bool
    {
        $port = $server->port;
        $start = time();
        $timeout = (int) $this->config->get('watchdog.port_timeout', 20);
        $hasWarned = false;
        while (! $server->stopping && (time() - $start) < $timeout) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
            if (is_resource($connection)) {
                fclose($connection);
                if (! $hasWarned) {
                    $hasWarned = true;
                    $this->warn("Port [{$port}] is still in use, waiting...");
                }
                sleep(1);
                continue;
            }

            $this->info("Port [{$port}] is available.");
            return true;
        }

        if (! $server->stopping) {
            $this->error("Port [{$port}] is not available.");
        }

        return false;
    }

    protected function mainPort(): int
    {
        return (int) $this->config->get('watchdog.server_ports.main', 9501);
    }

    /**
     * Write the pid while holding an exclusive lock for the lifetime of the watchdog.
     *
     * `watchdog:update` checks the lock to tell a running watchdog from a stale pid file.
     * The lock also refuses a second watchdog. Close-on-exec keeps the servers from
     * inheriting the handle, which would keep the lock held after the watchdog dies.
     */
    protected function writeWatchdogPidFile(int $pid): void
    {
        $handle = fopen($this->watchdogPidFile, 'ce');
        if ($handle === false) {
            throw new RuntimeException("Failed to open watchdog pid file [{$this->watchdogPidFile}].");
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException("Another watchdog is already running [{$this->watchdogPidFile}].");
        }

        ftruncate($handle, 0);
        fwrite($handle, (string) $pid);
        fflush($handle);

        $this->watchdogPidHandle = $handle;
    }

    protected function removeWatchdogPidFile(): void
    {
        if ($this->watchdogPidHandle === null) {
            return;
        }

        // Delete before unlocking so the pid is never readable without the lock held.
        $this->filesystem->delete($this->watchdogPidFile);
        flock($this->watchdogPidHandle, LOCK_UN);
        fclose($this->watchdogPidHandle);
        $this->watchdogPidHandle = null;
    }

    protected function getServerPid(): ?int
    {
        if (! $this->filesystem->exists($this->serverPidFile)) {
            return null;
        }

        return (int) $this->filesystem->get($this->serverPidFile);
    }

    protected function writeServerPidFile(int $pid): void
    {
        $this->filesystem->put($this->serverPidFile, (string) $pid);
    }

    protected function clearServerPidFile(): void
    {
        if (! $this->filesystem->exists($this->serverPidFile)) {
            return;
        }

        $this->filesystem->delete($this->serverPidFile);
    }

    /**
     * `exec` replaces the intermediate shell, so the spawned process is the server itself
     * and signals sent to it reach the server.
     */
    protected function getServerStartCommand(): string
    {
        $php = $this->config->get('watchdog.command.php', Application::phpBinary());
        $artisan = $this->config->get('watchdog.command.artisan', Application::artisanBinary());
        $command = $this->config->get('watchdog.command.start', 'serve');

        return "exec {$php} {$artisan} {$command}";
    }

    /**
     * Read the keys of the current env file without touching this process's environment.
     *
     * The env repository in Hypervel 0.4 is immutable, so values inherited from this
     * process would otherwise take precedence over the updated env file in the child.
     */
    protected function loadDotEnv(): array
    {
        $environmentFile = $this->app->environmentFilePath();

        return Dotenv::createArrayBacked(dirname($environmentFile), basename($environmentFile))
            ->safeLoad();
    }
}
