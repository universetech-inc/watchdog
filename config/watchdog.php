<?php

declare(strict_types=1);

use Hypervel\Console\Application;

return [
    // Pid file of the watchdog process itself. WATCHDOG_SERVER_PID_FILE is the deprecated
    // name of this variable and is still read as a fallback.
    'watchdog_pid_file' => env('WATCHDOG_PID_FILE', env('WATCHDOG_SERVER_PID_FILE', storage_path('framework/watchdog.pid'))),

    // Pid file written by the servers. Null uses `server.settings.pid_file`.
    'server_pid_file' => null,

    'server_ports' => [
        'main' => env('WATCHDOG_MAIN_SERVER_PORT', 9501),
        'backup' => env('WATCHDOG_BACKUP_SERVER_PORT', 9502),
    ],

    // Run as `exec {php} {artisan} {start}` through the shell. Values must be shell-escaped,
    // as the defaults are.
    'command' => [
        'start' => 'serve',
        'php' => Application::phpBinary(),
        'artisan' => Application::artisanBinary(),
    ],

    // Signal sent by `watchdog:update` to trigger a restart. Avoid SIGWINCH, which terminals
    // send to the foreground process group on every window resize.
    'reload_signal' => (int) env('WATCHDOG_RELOAD_SIGNAL', SIGUSR2),

    // Seconds to wait for a port to become free before starting a server on it.
    'port_timeout' => (int) env('WATCHDOG_PORT_TIMEOUT', 20),

    // Seconds for a spawned server to write its pid file, and for a server to exit after
    // SIGTERM before it is sent SIGKILL.
    'timeout' => (int) env('WATCHDOG_TIMEOUT', 30),

    // Start each server with the current env file values, even for keys that are also set
    // in the real environment. See README.
    'env_overload' => (bool) env('WATCHDOG_ENV_OVERLOAD', true),
];
