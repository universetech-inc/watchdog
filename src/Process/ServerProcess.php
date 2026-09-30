<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Process;

use Hypervel\Contracts\Process\InvokedProcess;

/**
 * Lifecycle state of one server started by the watchdog.
 *
 * The state is per instance because, during a restart, one server is being stopped on
 * purpose while another one must still be supervised on the same port.
 */
final class ServerProcess
{
    /**
     * Pid of the server master process, known once the server has written its pid file.
     */
    public ?int $pid = null;

    public ?InvokedProcess $process = null;

    /**
     * The server finished starting, so an exit from now on is a crash.
     */
    public bool $ready = false;

    /**
     * The watchdog asked the server to stop, so its exit is expected.
     */
    public bool $stopping = false;

    /**
     * The coroutine that owns the process has finished: the process was never spawned,
     * or it has exited and been reaped.
     */
    public bool $exited = false;

    /**
     * @param bool $sharesPort Whether the server binds the port alongside a running server
     *                         (reuse port strategy), so it must not wait for the port to be free.
     */
    public function __construct(
        public readonly int $port,
        public readonly bool $sharesPort = false
    ) {
    }
}
