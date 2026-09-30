<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Tests\Fakes;

use Closure;
use Hypervel\Coroutine\Coroutine;
use RuntimeException;
use UniverseTech\Watchdog\EventChannel;
use UniverseTech\Watchdog\Process\ServerLauncher;
use UniverseTech\Watchdog\Process\ServerProcess;

/**
 * Records every call and starts servers instantly, with pids counting up from 1000.
 */
class FakeServerLauncher implements ServerLauncher
{
    /**
     * @var list<string> e.g. "start 9501", "start 9501 shared", "stop 9501 (1000)"
     */
    public array $calls = [];

    /**
     * Ports whose next start fails.
     *
     * @var array<int, true>
     */
    public array $failStart = [];

    /**
     * Pids that cannot be stopped.
     *
     * @var array<int, true>
     */
    public array $failStop = [];

    /**
     * Seconds each stop takes, letting other coroutines run meanwhile.
     */
    public float $stopDelay = 0;

    /**
     * Called after each successful start with the new server.
     */
    public ?Closure $afterStart = null;

    /**
     * @var array<int, ServerProcess>
     */
    protected array $servers = [];

    protected int $nextPid = 1000;

    public function __construct(
        protected ?EventChannel $events = null
    ) {
    }

    public function start(int $port, bool $sharesPort = false): ServerProcess
    {
        $this->calls[] = "start {$port}" . ($sharesPort ? ' shared' : '');

        if (isset($this->failStart[$port])) {
            unset($this->failStart[$port]);
            throw new RuntimeException("Failed to start server. [{$port}]");
        }

        $server = new ServerProcess($port, $sharesPort);
        $server->pid = $this->nextPid++;
        $server->ready = true;
        $this->servers[spl_object_id($server)] = $server;

        if ($this->afterStart) {
            ($this->afterStart)($server);
        }

        return $server;
    }

    public function stop(ServerProcess $server): void
    {
        $this->calls[] = "stop {$server->port} ({$server->pid})";
        $server->stopping = true;

        if ($this->stopDelay > 0) {
            Coroutine::sleep($this->stopDelay);
        }

        if (isset($this->failStop[$server->pid])) {
            throw new RuntimeException("Failed to stop server. [{$server->port}]");
        }

        $server->exited = true;
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
     * Simulate a server that exits on its own, the way ProcessServerLauncher reports it.
     */
    public function crash(ServerProcess $server): void
    {
        $server->exited = true;
        $this->events?->reportExit($server);
    }
}
