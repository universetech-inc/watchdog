<?php

declare(strict_types=1);

/*
 * Console entry point for integration tests: `php watchdog.php watchdog:start|watchdog:update`.
 *
 * `vendor/bin/testbench` cannot be used because it installs its own SIGTERM/SIGUSR2 handlers.
 * The watchdog's settings come from the WATCHDOG_* env variables that config/watchdog.php
 * reads; this script only points the server command at the fixture server and keeps every
 * file in WATCHDOG_TEST_STATE_DIR.
 */

use Hypervel\Contracts\Console\Kernel as ConsoleKernel;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Foundation\Application as TestbenchApplication;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use UniverseTech\Watchdog\WatchdogServiceProvider;

require __DIR__ . '/../../vendor/autoload.php';

Bootstrapper::bootstrap();

$stateDir = (string) getenv('WATCHDOG_TEST_STATE_DIR');

$app = TestbenchApplication::create(
    resolvingCallback: static function ($app) use ($stateDir): void {
        // The env file is read by the watchdog itself (env_overload), not loaded at boot.
        $app->useEnvironmentPath($stateDir);
    },
    options: [
        'load_environment_variables' => false,
        'extra' => ['dont-discover' => ['*']],
    ],
);
$app->register(WatchdogServiceProvider::class);

$config = $app->make('config');
$config->set('server.settings.pid_file', $stateDir . '/server.pid');
$config->set('watchdog.command.php', escapeshellarg(PHP_BINARY));
$config->set('watchdog.command.artisan', escapeshellarg(__DIR__ . '/server.php'));

$kernel = $app->make(ConsoleKernel::class);
$input = new ArgvInput();
$status = $kernel->handle($input, new ConsoleOutput());
$kernel->terminate($input, $status);

exit($status);
