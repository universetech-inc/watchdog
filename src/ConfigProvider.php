<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog;

use UniverseTech\Watchdog\Console\WatchdogStartCommand;
use UniverseTech\Watchdog\Console\WatchdogUpdateCommand;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'commands' => [
                WatchdogStartCommand::class,
                WatchdogUpdateCommand::class,
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The configuration file of watchdog.',
                    'source' => __DIR__ . '/../publish/watchdog.php',
                    'destination' => BASE_PATH . '/config/autoload/watchdog.php',
                ],
            ],
        ];
    }
}
