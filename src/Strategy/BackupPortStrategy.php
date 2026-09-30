<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Strategy;

use Psr\Log\LoggerInterface;
use UniverseTech\Watchdog\Process\ServerLauncher;
use UniverseTech\Watchdog\Process\ServerProcess;
use UniverseTech\Watchdog\WatchdogConfig;

/**
 * Serve from the backup port while the main port is handed over. Relies on the proxy
 * falling back to the backup port.
 */
class BackupPortStrategy implements RestartStrategy
{
    public function __construct(
        protected WatchdogConfig $config,
        protected LoggerInterface $logger
    ) {
    }

    public function preflight(): void
    {
    }

    /**
     * `--port` overrides the port of the first HTTP server whatever env variable the app's
     * `config/server.php` reads (`SERVER_PORT` in the Hypervel 0.4 skeleton).
     */
    public function serverArguments(int $port): string
    {
        return "--port={$port}";
    }

    public function serverEnvironment(int $port): array
    {
        return [];
    }

    public function restart(ServerProcess $current, ServerLauncher $launcher): ServerProcess
    {
        // Until the backup server is up, a failure leaves the original server untouched.
        $this->logger->info('Starting new server...');
        $backup = $launcher->start($this->config->backupPort);

        $this->logger->info("Stopping original server (pid: [{$current->pid}])...");
        $launcher->stop($current);

        $this->logger->info('Transferring server port...');
        $next = $launcher->start($this->config->mainPort);

        $this->logger->info("Stopping backup server (pid: [{$backup->pid}])...");
        $launcher->stop($backup);

        return $next;
    }
}
