<?php

declare(strict_types=1);

/*
 * Stand-in for `php artisan serve` in integration tests.
 *
 * Reads its port like the watchdog passes it (`--port=` for backup_port, WATCHDOG_REUSE_PORT
 * for reuse_port), writes the Swoole pid file, and answers every request with the master pid
 * and port as JSON. Flag files in WATCHDOG_TEST_STATE_DIR make it misbehave:
 *
 * - crash-on-boot-{port}: exit with code 1 before listening
 * - hang-on-boot-{port}: never start listening
 * - ignore-sigterm-{port}: plain socket server that ignores SIGTERM
 * - boot-delay: sleep that many milliseconds before listening
 */

$stateDir = (string) getenv('WATCHDOG_TEST_STATE_DIR');
$pidFile = $stateDir . '/server.pid';

$recordPid = static function (int $pid) use ($stateDir): void {
    @mkdir($stateDir . '/pids');
    touch($stateDir . '/pids/' . $pid);
};
$recordPid(getmypid());

$reusePort = (int) getenv('WATCHDOG_REUSE_PORT');
$port = $reusePort;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--port=')) {
        $port = (int) substr($argument, 7);
    }
}

$flag = static fn (string $name): string => $stateDir . '/' . $name;
file_put_contents($flag('ports.log'), $port . PHP_EOL, FILE_APPEND);

if (file_exists($flag("crash-on-boot-{$port}"))) {
    fwrite(STDOUT, "[fixture] crash on boot [{$port}]\n");
    exit(1);
}

if (file_exists($flag("hang-on-boot-{$port}"))) {
    fwrite(STDOUT, "[fixture] hanging on boot [{$port}]\n");
    sleep(3600);
}

if (file_exists($flag('boot-delay'))) {
    usleep((int) file_get_contents($flag('boot-delay')) * 1000);
}

if (file_exists($flag("ignore-sigterm-{$port}"))) {
    pcntl_signal(SIGTERM, SIG_IGN);
    $socket = stream_socket_server("tcp://127.0.0.1:{$port}");
    file_put_contents($pidFile, (string) getmypid());
    fwrite(STDOUT, "[fixture] ignoring SIGTERM [{$port}]\n");
    while (true) {
        if ($connection = @stream_socket_accept($socket, 1)) {
            $body = json_encode(['pid' => getmypid(), 'port' => $port]);
            fwrite($connection, "HTTP/1.0 200 OK\r\nContent-Length: " . strlen($body) . "\r\n\r\n{$body}");
            fclose($connection);
        }
    }
}

// In reuse_port mode the watchdog's service provider would add an anchor server first, so
// that SO_REUSEPORT applies to the real port. This fixture does not boot the app, so it
// builds the same shape itself.
$server = $reusePort
    ? new Swoole\Http\Server('127.0.0.1', 0, SWOOLE_PROCESS)
    : new Swoole\Http\Server('127.0.0.1', $port, SWOOLE_PROCESS);

$server->set([
    'worker_num' => 1,
    'pid_file' => $pidFile,
    'enable_reuse_port' => (bool) $reusePort,
    'max_wait_time' => 1,
    'log_level' => SWOOLE_LOG_WARNING,
]);

// Like Hypervel's Server::initServers(), fail when an added port cannot be bound. Outside
// Linux, Swoole does not let a second server bind a port with enable_reuse_port.
if ($reusePort && $server->listen('127.0.0.1', $port, SWOOLE_SOCK_TCP) === false) {
    fwrite(STDOUT, "[fixture] failed to listen on [{$port}]: " . swoole_strerror(swoole_last_error()) . "\n");
    exit(1);
}

$server->on('start', static function (Swoole\Http\Server $server) use ($port, $recordPid): void {
    $recordPid($server->master_pid);
    fwrite(STDOUT, "[fixture] master {$server->master_pid} listening [{$port}]\n");
});
$server->on('managerStart', static fn (Swoole\Http\Server $server) => $recordPid($server->manager_pid));
$server->on('workerStart', static fn (Swoole\Http\Server $server) => $recordPid($server->worker_pid));
// The watchdog exec's this script, so this process becomes the Swoole master.
$masterPid = getmypid();
$server->on('request', static function ($request, $response) use ($masterPid, $port): void {
    $response->end(json_encode(['pid' => $masterPid, 'port' => $port]));
});

$server->start();
