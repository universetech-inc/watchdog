<?php

declare(strict_types=1);

use Hypervel\Console\Application;

return [
    'watchdog_pid_file' => env('WATCHDOG_SERVER_PID_FILE', storage_path('framework/watchdog.pid')),

    'server_ports' => [
        'main' => env('WATCHDOG_MAIN_SERVER_PORT', 9501),
        'backup' => env('WATCHDOG_BACKUP_SERVER_PORT', 9502),
    ],

    'command' => [
        'start' => 'serve',
        'php' => Application::phpBinary(),
        'artisan' => Application::artisanBinary(),
    ],

    'timeout' => 30,

    'env_overload' => true,
];
