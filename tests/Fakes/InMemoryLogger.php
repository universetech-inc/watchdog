<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Tests\Fakes;

use Psr\Log\AbstractLogger;
use Stringable;

class InMemoryLogger extends AbstractLogger
{
    /**
     * @var list<array{string, string}>
     */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [(string) $level, (string) $message];
    }

    /**
     * @return list<string>
     */
    public function messages(?string $level = null): array
    {
        $records = $level === null
            ? $this->records
            : array_filter($this->records, static fn (array $record): bool => $record[0] === $level);

        return array_values(array_map(static fn (array $record): string => $record[1], $records));
    }
}
