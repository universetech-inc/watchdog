<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Signal;

interface SignalRegistrar
{
    /**
     * Handle the signal for the rest of the process lifetime.
     *
     * @param callable(int): void $handler
     */
    public function register(int $signal, callable $handler): void;
}
