<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Console;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Support\Filesystem\Filesystem;
use LaravelHyperf\Coroutine\Channel;
use LaravelHyperf\Coroutine\Coroutine;
use LaravelHyperf\Foundation\Console\Application;
use LaravelHyperf\Foundation\Console\Command;
use LaravelHyperf\Support\Facades\Process;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Throwable;

class WatchdogStartCommand extends Command
{
    protected ?string $signature = 'watchdog:start';

    protected string $description = 'Start watchdog for servers.';

    protected ?Channel $channel = null;

    protected bool $needRestart = false;

    protected bool $isTransferring = false;

    protected string $pidFile;

    protected array $pids = [];

    public function __construct(
        protected ContainerInterface $container,
        protected ConfigInterface $config,
        protected Filesystem $filesystem
    ) {
        parent::__construct();

        $this->pidFile = $this->config->get('watchdog.server_pid_file', BASE_PATH . '/runtime/watchdog.pid');
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

        $this->removePidFile();
    }

    protected function writePidFile(int $pid): void
    {
        $this->filesystem->put($this->pidFile, $pid);
    }

    protected function removePidFile(): void
    {
        if (! $this->filesystem->exists($this->pidFile)) {
            return;
        }

        $this->filesystem->delete($this->pidFile);
    }

    protected function init(): void
    {
        $this->channel = new Channel(1);
        $this->channel->push(true);

        $this->registerSignal();
        $this->writePidFile(posix_getpid());
    }

    protected function registerSignal(): void
    {
        pcntl_signal(SIGWINCH, function () {
            $this->needRestart = true;
            $this->isTransferring = true;
            $this->channel->push(true);
        });
    }

    protected function startServer(?int $port = null, ?int $timeout = null): int
    {
        $port = $port ?: (int) $this->config->get('watchdog.ports.main', 9501);
        $env = [
            'FORCE_COLOR' => 'true',
            'TERM' => 'xterm-256color',
            'HTTP_SERVER_PORT' => $port,
        ];

        $process = null;
        $failed = false;

        Coroutine::create(function () use ($env, &$process, &$failed, $port) {
            $this->info("Starting server [{$port}]...");

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
        while (! $process || ! $process->running()) {
            if ($failed || (time() - $start) > $timeout) {
                throw new RuntimeException("Failed to start server. [{$port}]");
            }
            usleep(100000);
        }

        return $process->id();
    }

    protected function restartServer(): void
    {
        $this->info('Restarting server...');

        if (! $this->pids['current'] ?? null) {
            throw new RuntimeException("Current server pid: {$this->pids['current']} is not found.");
        }

        $this->info('Starting new server...');
        $this->pids['backup'] = $this->startServer((int) $this->config->get('watchdog.ports.backup', 9502));

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
        while ((time() - $start) < $timeout) {
            posix_kill($pid, SIGTERM);
            if (! posix_kill($pid, 0)) {
                return;
            }
            $this->warn("Process [{$pid}] is still alive, retrying...");

            sleep(1);
        }

        throw new RuntimeException("Failed to kill process [{$pid}].");
    }

    protected function getServerStartCommand(): string
    {
        $php = $this->config->get('watchdog.command.php', Application::phpBinary());
        $artisan = $this->config->get('watchdog.command.artisan', Application::artisanBinary());
        $command = $this->config->get('watchdog.command.start', 'start');

        return "{$php} {$artisan} {$command}";
    }
}
