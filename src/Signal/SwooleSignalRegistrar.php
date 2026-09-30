<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Signal;

use RuntimeException;
use Swoole\Process;

/**
 * Handlers run in their own coroutine and do not keep the event loop alive.
 *
 * They are never unregistered: once Swoole has handled a signal, it swallows later
 * deliveries that have no callback instead of applying the default action.
 */
class SwooleSignalRegistrar implements SignalRegistrar
{
    public function register(int $signal, callable $handler): void
    {
        if (! Process::signal($signal, $handler)) {
            throw new RuntimeException("Failed to register handler for signal [{$signal}].");
        }
    }
}
