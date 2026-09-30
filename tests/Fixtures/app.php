<?php

declare(strict_types=1);

/*
 * A real Hypervel application for integration tests: `php app.php serve`.
 *
 * It registers the watchdog's service provider, so a WATCHDOG_REUSE_PORT env variable
 * reconfigures the servers exactly as in an application. `GET /` answers the pid of the
 * process that handled it. Files kept in WATCHDOG_TEST_STATE_DIR:
 * - pids/{pid}: the Swoole master and worker pids, for cleanup
 * - main-port-{pid}: the port of the Swoole main server, i.e. the anchor in reuse port mode
 */

use Hypervel\Contracts\Console\Kernel as ConsoleKernel;
use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\Core\Events\BeforeMainServerStart;
use Hypervel\HttpServer\Server as HttpServer;
use Hypervel\Routing\Router;
use Hypervel\Server\Event;
use Hypervel\Server\ServerInterface;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Foundation\Application as TestbenchApplication;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use UniverseTech\Watchdog\WatchdogServiceProvider;

require __DIR__ . '/../../vendor/autoload.php';

Bootstrapper::bootstrap();

$stateDir = (string) getenv('WATCHDOG_TEST_STATE_DIR');

// As an application's artisan binary does for `serve`.
putenv('APP_RUNNING_IN_CONSOLE=false');
$_ENV['APP_RUNNING_IN_CONSOLE'] = $_SERVER['APP_RUNNING_IN_CONSOLE'] = 'false';

$app =TestbenchApplication::create(options: [
    'load_environment_variables' => false,
    'extra' => ['dont-discover' => ['*']],
]);

// Configured before the provider registers, which is when the reuse port setup reads it.
$config = $app->make('config');
$config->set('server.mode', SWOOLE_BASE);
$config->set('server.servers', [[
    'name' => 'http',
    'type' => ServerInterface::SERVER_HTTP,
    'host' => '127.0.0.1',
    'port' => 0,
    'sock_type' => SWOOLE_SOCK_TCP,
    'callbacks' => [
        Event::ON_REQUEST => [HttpServer::class, 'onRequest'],
    ],
]]);
$config->set('server.settings.worker_num', 1);
$config->set('server.settings.pid_file', $stateDir . '/server.pid');

$app->register(WatchdogServiceProvider::class);

$app->make(Router::class)->get('/', static fn (): string => (string) getmypid());

$recordPid = static function () use ($stateDir): void {
    @mkdir($stateDir . '/pids');
    touch($stateDir . '/pids/' . getmypid());
};
$app->make('events')->listen(BeforeMainServerStart::class, static function (BeforeMainServerStart $event) use ($stateDir, $recordPid): void {
    $recordPid();
    file_put_contents($stateDir . '/main-port-' . getmypid(), (string) $event->server->port);
});
$app->make('events')->listen(AfterWorkerStart::class, $recordPid);

$kernel = $app->make(ConsoleKernel::class);
$input = new ArgvInput();
$status = $kernel->handle($input, new ConsoleOutput());
$kernel->terminate($input, $status);

exit($status);
