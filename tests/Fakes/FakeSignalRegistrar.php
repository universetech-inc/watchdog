<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Tests\Fakes;

use RuntimeException;
use UniverseTech\Watchdog\Signal\SignalRegistrar;

class FakeSignalRegistrar implements SignalRegistrar
{
    /**
     * @var array<int, callable(int): void>
     */
    public array $handlers = [];

    public ?int $failOn = null;

    public function register(int $signal, callable $handler): void
    {
        if ($signal === $this->failOn) {
            throw new RuntimeException("Failed to register handler for signal [{$signal}].");
        }

        $this->handlers[$signal] = $handler;
    }

    /**
     * Run the handler inline, the way Swoole runs it in its own coroutine.
     */
    public function dispatch(int $signal): void
    {
        ($this->handlers[$signal] ?? throw new RuntimeException("No handler for signal [{$signal}]."))($signal);
    }
}
