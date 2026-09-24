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
use Hypervel\Engine\Signal;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Throwable;

#[AsCommand(name: 'watchdog:start')]
class WatchdogStartCommand extends Command
{
    protected ?string $signature = 'watchdog:start';

    protected string $description = 'Start watchdog for servers.';

    protected ?Channel $channel = null;

    protected bool $needRestart = false;

    protected bool $isTransferring = false;

    protected bool $running = false;

    protected string $watchdogPidFile;

    protected string $serverPidFile;

    protected array $pids = [];

    public function __construct(
        protected ApplicationContract $app,
        protected Repository $config,
        protected Filesystem $filesystem
    ) {
        parent::__construct();

        $this->watchdogPidFile = $this->config->get('watchdog.watchdog_pid_file', $this->app->storagePath('framework/watchdog.pid'));
        $this->serverPidFile = $this->config->get(
            'watchdog.server_pid_file',
            $this->config->get('server.settings.pid_file', $this->app->storagePath('framework/hypervel.pid'))
        );
    }

    public function handle()
    {
        $this->init();

        while ($this->channel->pop(-1)) {
            try {
                $pid = $this->needRestart
                    ? $this->restartServer()
                    : $this->startServer();

                if (! $this->needRestart) {
                    $this->pids['current'] = $pid;
                }
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());
                break;
            } catch (Throwable $e) {
                $this->error($e->getMessage());
            }
        }

        $this->running = false;
        $this->removeWatchdogPidFile();
    }

    protected function init(): void
    {
        $this->channel = new Channel(1);
        $this->channel->push(true);
        $this->running = true;

        $this->registerSignal();
        $this->writeWatchdogPidFile(posix_getpid());
    }

    protected function registerSignal(): void
    {
        // Wait with a timeout so the loop can observe `running` and let the process exit.
        Coroutine::create(function () {
            while ($this->running) {
                if (! Signal::wait(SIGWINCH, 1)) {
                    continue;
                }

                $this->needRestart = true;
                $this->isTransferring = true;
                $this->channel->push(true);
            }
        });
    }

    protected function startServer(?int $port = null, ?int $timeout = null): int
    {
        $port = $port ?: (int) $this->config->get('watchdog.server_ports.main', 9501);
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

        $process = null;
        $failed = false;

        Coroutine::create(function () use ($env, &$process, &$failed, $port) {
            $this->info("Starting server [{$port}]...");

            if (! $this->waitPortAvailable($port, 20)) {
                $failed = true;
                $this->error("Port [{$port}] is not available.");
                $this->channel->push(false);
            }

            try {
                $process = Process::forever()
                    ->env($env)
                    ->start($this->getServerStartCommand());

                $process->wait(function ($type, $buffer) {
                    $this->output->write($buffer);
                });
            } catch (ProcessSignaledException $e) {
                $this->error($e->getMessage());
            }

            if (! $this->isTransferring) {
                $failed = true;
                $this->channel->push(false);
                $this->error("Server stopped. [{$port}]");
            }
        });

        $start = time();
        $timeout = $timeout ?: (int) $this->config->get('watchdog.timeout', 30);
        $pid = null;
        while (! $process || ! $process->running() || ! $pid = $this->getServerPid()) {
            if ($failed || (time() - $start) > $timeout) {
                throw new RuntimeException("Failed to start server. [{$port}]");
            }
            usleep(100000);
        }

        $this->info("Server pid: {$pid} started successfully. [{$port}]");

        return $pid;
    }

    protected function restartServer(): void
    {
        $this->info('Restarting server...');

        if (! ($this->pids['current'] ?? null)) {
            throw new RuntimeException('Current server pid is not found.');
        }

        $this->info('Starting new server...');
        $this->pids['backup'] = $this->startServer((int) $this->config->get('watchdog.server_ports.backup', 9502));

        $this->info("Stopping original server (pid: [{$this->pids['current']}])...");
        $this->kill($this->pids['current']);

        $this->info('Transferring server port...');
        $this->pids['current'] = $this->startServer();

        $this->info("Stopping backup server (pid: [{$this->pids['backup']}])...");
        $this->kill($this->pids['backup']);

        $this->info('Server restarted successfully.');

        $this->isTransferring = false;
    }

    protected function kill(int $pid, ?int $timeout = null): void
    {
        if (! posix_kill($pid, 0)) {
            $this->warn("Process [{$pid}] doesn't exist.");
            return;
        }

        $start = time();
        $timeout = $timeout ?: (int) $this->config->get('watchdog.timeout', 30);
        $hasWarned = false;
        while ((time() - $start) < $timeout) {
            posix_kill($pid, SIGTERM);
            if (! posix_kill($pid, 0)) {
                $this->info("Process [{$pid}] is killed.");
                return;
            }

            if (! $hasWarned) {
                $hasWarned = true;
                $this->warn("Process [{$pid}] is still alive, waiting...");
            }

            sleep(1);
        }

        throw new RuntimeException("Failed to kill process [{$pid}].");
    }

    protected function waitPortAvailable(int $port, ?int $timeout = null): bool
    {
        $start = time();
        $timeout = $timeout ?: (int) $this->config->get('watchdog.timeout', 30);
        $hasWarned = false;
        while ((time() - $start) < $timeout) {
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

        return false;
    }

    protected function writeWatchdogPidFile(int $pid): void
    {
        $this->filesystem->put($this->watchdogPidFile, (string) $pid);
    }

    protected function removeWatchdogPidFile(): void
    {
        if (! $this->filesystem->exists($this->watchdogPidFile)) {
            return;
        }

        $this->filesystem->delete($this->watchdogPidFile);
    }

    protected function getServerPid(): ?int
    {
        if (! $this->filesystem->exists($this->serverPidFile)) {
            return null;
        }

        return (int) $this->filesystem->get($this->serverPidFile);
    }

    protected function clearServerPidFile(): void
    {
        if (! $this->filesystem->exists($this->serverPidFile)) {
            return;
        }

        $this->filesystem->delete($this->serverPidFile);
    }

    protected function getServerStartCommand(): string
    {
        $php = $this->config->get('watchdog.command.php', Application::phpBinary());
        $artisan = $this->config->get('watchdog.command.artisan', Application::artisanBinary());
        $command = $this->config->get('watchdog.command.start', 'start');

        return "{$php} {$artisan} {$command}";
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
