<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Strategy;

use UniverseTech\Watchdog\Process\ServerLauncher;
use UniverseTech\Watchdog\Process\ServerProcess;

/**
 * How a restart replaces the server without closing its public port.
 */
interface RestartStrategy
{
    /**
     * Refuse setups the strategy cannot handle, before anything starts.
     */
    public function preflight(): void;

    /**
     * Arguments appended to the command that starts a server on the port.
     */
    public function serverArguments(int $port): string;

    /**
     * Env variables given to every server started on the port.
     *
     * @return array<string, string>
     */
    public function serverEnvironment(int $port): array;

    /**
     * Replace the current server.
     *
     * A failure before the current server is stopped must leave it running as the only
     * live server, so that the watchdog can keep supervising it.
     *
     * @return ServerProcess the server now serving the main port
     */
    public function restart(ServerProcess $current, ServerLauncher $launcher): ServerProcess;
}
