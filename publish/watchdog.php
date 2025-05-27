<?php

declare(strict_types=1);

use Hypervel\Foundation\Console\Application;

use function Hypervel\Support\env;

return [
    'watchdog_pid_file' => env('WATCHDOG_SERVER_PID_FILE', BASE_PATH . '/runtime/watchdog.pid'),

    'server_ports' => [
        'main' => env('WATCHDOG_MAIN_SERVER_PORT', 9501),
        'backup' => env('WATCHDOG_BACKUP_SERVER_PORT', 9502),
    ],

    'command' => [
        'start' => 'start',
        'php' => Application::phpBinary(),
        'artisan' => Application::artisanBinary(),
    ],

    'timeout' => 30,

    'env_overload' => true,
];
