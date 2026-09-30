<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Tests\Unit\Console;

use Hypervel\Console\Command;
use Hypervel\Testing\UnitTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LogLevel;
use Stringable;
use UniverseTech\Watchdog\Console\CommandLogger;

class CommandLoggerTest extends UnitTestCase
{
    public static function levels(): array
    {
        return [
            [LogLevel::EMERGENCY, 'error'],
            [LogLevel::ALERT, 'error'],
            [LogLevel::CRITICAL, 'error'],
            [LogLevel::ERROR, 'error'],
            [LogLevel::WARNING, 'warn'],
            [LogLevel::NOTICE, 'info'],
            [LogLevel::INFO, 'info'],
            [LogLevel::DEBUG, 'info'],
        ];
    }

    #[DataProvider('levels')]
    public function testLevelMapsToCommandStyle(string $level, string $method): void
    {
        $command = Mockery::mock(Command::class);
        $command->shouldReceive($method)->once()->with('message');

        (new CommandLogger($command))->log($level, 'message');
    }

    public function testStringableMessage(): void
    {
        $command = Mockery::mock(Command::class);
        $command->shouldReceive('info')->once()->with('stringable');

        (new CommandLogger($command))->info(new class implements Stringable {
            public function __toString(): string
            {
                return 'stringable';
            }
        });
    }
}
