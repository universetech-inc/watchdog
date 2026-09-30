<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Console;

use Hypervel\Console\Command;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * Writes log records to a command's output with its error/warn/info styles.
 */
class CommandLogger extends AbstractLogger
{
    public function __construct(
        protected Command $command
    ) {
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $message = (string) $message;

        match ($level) {
            LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL, LogLevel::ERROR => $this->command->error($message),
            LogLevel::WARNING => $this->command->warn($message),
            default => $this->command->info($message),
        };
    }
}
