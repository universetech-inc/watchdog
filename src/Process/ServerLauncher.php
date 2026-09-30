<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Process;

/**
 * Starts and stops servers, and keeps track of the ones still running.
 */
interface ServerLauncher
{
    /**
     * Start a server and wait until it has written its pid file.
     *
     * @param bool $sharesPort whether the server binds the port alongside a running server
     */
    public function start(int $port, bool $sharesPort = false): ServerProcess;

    /**
     * Stop a server and wait until it has exited.
     */
    public function stop(ServerProcess $server): void;

    /**
     * Servers started and not yet confirmed stopped, keyed by object id.
     *
     * @return array<int, ServerProcess>
     */
    public function servers(): array;

    public function isLive(ServerProcess $server): bool;
}
