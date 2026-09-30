<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Strategy;

use Psr\Log\LoggerInterface;
use RuntimeException;
use UniverseTech\Watchdog\Process\ServerLauncher;
use UniverseTech\Watchdog\Process\ServerProcess;
use UniverseTech\Watchdog\SharedPortConfigurator;
use UniverseTech\Watchdog\WatchdogConfig;

/**
 * Start the new server on the main port with SO_REUSEPORT, then stop the old one. Both
 * servers accept connections until the original one stops, so the port never closes.
 */
class ReusePortStrategy implements RestartStrategy
{
    /**
     * Linux 5.14+ setting that hands connections queued on a closing SO_REUSEPORT listener
     * over to another listener of the same port instead of resetting them.
     */
    public const string TCP_MIGRATE_REQ_PATH = '/proc/sys/net/ipv4/tcp_migrate_req';

    public function __construct(
        protected WatchdogConfig $config,
        protected LoggerInterface $logger,
        protected string $osFamily = PHP_OS_FAMILY,
        protected string $tcpMigrateReqPath = self::TCP_MIGRATE_REQ_PATH
    ) {
    }

    /**
     * Refuse setups the reuse port strategy cannot handle and warn about a weaker one.
     */
    public function preflight(): void
    {
        $webSocketServers = SharedPortConfigurator::webSocketServers($this->config->servers);
        if ($webSocketServers !== []) {
            throw new RuntimeException(sprintf(
                'The reuse_port strategy does not support WebSocket servers [%s]. Use the backup_port strategy instead.',
                implode(', ', $webSocketServers)
            ));
        }

        // Elsewhere Swoole fails to bind a second server to the port, so every restart would
        // be aborted.
        if ($this->osFamily !== 'Linux') {
            throw new RuntimeException("The reuse_port strategy requires Linux; Swoole cannot bind two servers to the same port on {$this->osFamily}. Use the backup_port strategy instead.");
        }

        if (@file_get_contents($this->tcpMigrateReqPath) === "0\n") {
            $this->logger->warning('net.ipv4.tcp_migrate_req is 0: connections queued on a stopping server are reset. Set it to 1 (Linux 5.14+).');
        }
    }

    /**
     * No `--port`: the first HTTP server is the anchor, so SharedPortConfigurator sets the
     * port instead.
     */
    public function serverArguments(int $port): string
    {
        return '';
    }

    /**
     * Every server needs SO_REUSEPORT, including the first one, or the next cannot join it.
     */
    public function serverEnvironment(int $port): array
    {
        return [SharedPortConfigurator::ENV_PORT => (string) $port];
    }

    public function restart(ServerProcess $current, ServerLauncher $launcher): ServerProcess
    {
        // Until the new server is up, a failure leaves the original server untouched.
        $this->logger->info('Starting new server on the same port...');
        $next = $launcher->start($this->config->mainPort, sharesPort: true);

        $this->logger->info("Stopping original server (pid: [{$current->pid}])...");
        $launcher->stop($current);

        return $next;
    }
}
