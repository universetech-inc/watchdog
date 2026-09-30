<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog;

use Hypervel\Engine\Channel;
use UniverseTech\Watchdog\Process\ServerProcess;

/**
 * Mailbox between the producers (signal handlers, server coroutines) and the event loop.
 *
 * It carries `true` for a start/restart request, `false` to wake the loop for termination,
 * and a ServerProcess that exited unexpectedly. Its capacity of one merges the restart
 * requests received while one is pending.
 */
class EventChannel
{
    protected Channel $channel;

    public function __construct()
    {
        $this->channel = new Channel(1);
    }

    public function request(float $timeout = -1): bool
    {
        return $this->channel->push(true, $timeout);
    }

    public function wake(float $timeout): bool
    {
        return $this->channel->push(false, $timeout);
    }

    /**
     * Blocks until the loop takes the event or the channel is closed.
     */
    public function reportExit(ServerProcess $server): bool
    {
        return $this->channel->push($server);
    }

    /**
     * @return null|bool|ServerProcess null when the timeout expires, false once closed
     */
    public function next(float $timeout): null|bool|ServerProcess
    {
        $event = $this->channel->pop($timeout);
        if ($event === false && $this->channel->isTimeout()) {
            return null;
        }

        return $event;
    }

    public function close(): void
    {
        $this->channel->close();
    }
}
