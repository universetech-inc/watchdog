<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Strategy;

/**
 * Values of the `watchdog.strategy` setting.
 */
enum StrategyType: string
{
    case BackupPort = 'backup_port';

    case ReusePort = 'reuse_port';
}
