# Watchdog

Restarts a Hypervel HTTP server without closing its public port.

Requires PHP 8.4+, Swoole 6.2+, `ext-pcntl`, `ext-posix`, and Hypervel 0.4.

## How a restart works

`watchdog:start` starts `php artisan serve` on the main port and keeps it running as a child process.
The reload signal replaces it with a new server that runs the current code and `.env`. The
`strategy` setting chooses how the port stays open during that replacement.

| | `backup_port` (default) | `reuse_port` |
| --- | --- | --- |
| Ports | Main port, plus a backup port during restarts | Main port only |
| Proxy | Must fall back to the backup port | One upstream |
| Servers stopped per restart | 2 (original and backup) | 1 (original) |
| Platform | Any | Linux |
| WebSocket servers (e.g. Reverb) | Their ports must be free for a second instance | Not supported |

In both strategies, stopping a server cuts off the requests still in flight on it. A strategy that
stops fewer servers cuts off fewer requests.

The table below counts client errors in a test on Hypervel 0.4 and Linux. Each run was one restart
behind nginx, with 50 concurrent clients and about 24,000 requests:

| Strategy | `GET` errors | `POST` errors |
| --- | --- | --- |
| `backup_port` | 0–47 | 171 |
| `reuse_port` | 0 | 54 |

### `backup_port`

1. Starts a backup server on the backup port (default `9502`) and waits for it to write its pid file.
2. Sends `SIGTERM` to the original server and waits for it to exit.
3. Starts a new server on the main port (default `9501`).
4. Sends `SIGTERM` to the backup server and restores the server pid file for the new server.

Between steps 2 and 3, **nothing listens on the main port**. The restart has no downtime only if the
proxy or load balancer in front falls back to the backup port during that window. See
[Proxy requirements](#proxy-requirements).

### `reuse_port`

1. Starts a new server on the main port while the original one still listens on it. Both use
   `SO_REUSEPORT`, and the kernel spreads new connections between them.
2. Sends `SIGTERM` to the original server and waits for it to exit.
3. Restores the server pid file for the new server.

The main port never closes. The first start still waits for the port to be free, so a server left
behind by a previous watchdog is never joined.

Swoole binds the first configured server before `enable_reuse_port` applies. To work around this, the
watchdog adds a `watchdog-anchor` server in front of your servers. The anchor listens on a random
loopback port and answers `404` to everything, which turns the main port into a port Swoole adds
afterwards, where the setting applies. This change happens only in servers the watchdog starts; a
plain `php artisan serve` is unaffected.

### Failures

If starting the new server fails, for example because the new code crashes on boot, the watchdog stops
it, aborts the restart, and keeps supervising the original server. A failure in a later step leaves no
consistent state to fall back to. In that case the watchdog stops every server it started and exits
with code `1`.

A server that exits on its own, outside a restart, is treated as a crash. The watchdog stops any other
server it started and exits with code `1`.

The watchdog considers a server started once it writes its pid file. It does not send a request to
check that the server works. Code that fails only when a request loads it is therefore deployed anyway.

## Installation

```bash
composer require universetech-inc/watchdog
php artisan vendor:publish --tag=watchdog-config
```

### Application requirements

With `backup_port`, the watchdog starts each server with `php artisan serve --port={port}`. `serve`
applies the option to the first HTTP server in `config/server.php`, whichever env variable that entry
reads. With `reuse_port`, the watchdog's service provider sets that port itself, because the first
HTTP server is then the anchor.

Each server also gets the port in the `HTTP_SERVER_PORT` environment variable, for apps that read the
port from there.

With `backup_port`, every other port the app binds, such as a WebSocket server, must be free for a
second server instance. Otherwise the backup server fails to start and every restart is aborted.

`reuse_port` has additional requirements:

- **Linux 3.9+.** On other systems Swoole cannot bind a second server to the same port, so
  `watchdog:start` refuses to start and exits with code `1`.
- **Linux 5.14+ with `net.ipv4.tcp_migrate_req=1`, recommended.** When the original server stops, the
  kernel then hands its queued connections to the new server instead of resetting them. The watchdog
  warns when the setting is `0`. In a container, set it with `--sysctl net.ipv4.tcp_migrate_req=1`.
- **No WebSocket servers.** Swoole constructs them before the anchor. `watchdog:start` refuses to
  start and exits with code `1`.
- **`UniverseTech\Watchdog\WatchdogServiceProvider` must be registered in the app.** Package discovery
  does this unless the app excludes the package with `dont-discover`.
- **Any process of the same user can bind the port too.** This is how `SO_REUSEPORT` works.

Both servers write the same pid file (`server.settings.pid_file`). The watchdog clears it before
each start and restores it when a restart finishes, or points it back at the original server if the
restart is aborted.

### Proxy requirements

With `reuse_port`, a single upstream is enough:

```nginx
upstream hypervel {
    server 127.0.0.1:9501 max_fails=0;
}
```

With `backup_port`, route traffic to the main port and fall back to the backup port when the
connection is refused:

```nginx
upstream hypervel {
    server 127.0.0.1:9501 max_fails=0;
    server 127.0.0.1:9502 max_fails=0 backup;
}
```

`max_fails=0` keeps nginx from marking a server as unavailable. Every request tries the main port
first and falls back to the backup port only if that attempt fails. With `max_fails=1`, a restart marks
the main port as failed when the original server stops, then the backup port when the backup server
stops. The new server is already running at that point, but nginx has no live upstream until
`fail_timeout` expires and answers `502` in the meantime.

A refused connection is always retried on the backup port, so requests arriving between steps 2 and 3
are served.

In both strategies, requests in flight on a server that is stopping are cut off. nginx retries them
only if they are idempotent (`GET`, `HEAD`, ...). Non-idempotent requests such as `POST` fail with
`502` unless `proxy_next_upstream non_idempotent` is set, which can process a request twice.

## Usage

```bash
php artisan watchdog:start    # run under a process supervisor
php artisan watchdog:update   # trigger a restart, e.g. at the end of a deploy
```

### Signals

| Signal | Effect |
| --- | --- |
| `reload_signal` (default `SIGUSR2`) | Restart the server as described above. Signals received during a restart are merged into at most one follow-up restart. |
| `SIGTERM`, `SIGINT` | Send `SIGTERM` to every server the watchdog started, wait for them to exit, then exit. A restart already in progress is finished first. |

A server that does not exit within `timeout` seconds of `SIGTERM` is sent `SIGKILL`. Its worker processes
may outlive it, and if the server runs in `SWOOLE_BASE` mode they can keep holding the port.

Give your supervisor enough time to stop the watchdog: allow at least `timeout` seconds for each
running server, plus the time a restart in progress needs to finish.

### Pid file and lock

While it runs, the watchdog holds an exclusive lock on its pid file. As a result:

- A second `watchdog:start` with the same pid file refuses to start.
- `watchdog:update` sends the signal only while the lock is held. A pid file left behind by a killed
  watchdog is therefore never used, even if its pid now belongs to another process that the reload
  signal would terminate.

### Exit codes

`watchdog:start` exits with:

- `0` after a `SIGTERM`/`SIGINT` shutdown.
- `1` when a server stops unexpectedly, the initial start fails, or a restart fails after the original
  server was stopped. By then the watchdog has stopped every server it started, so the port is free.
  Configure the supervisor to restart the watchdog on a non-zero exit code.

`watchdog:update` exits with `1` when the watchdog is not running or the signal cannot be sent.
Deploy scripts can therefore fail the deploy on it. Exit code `0` only means the signal was delivered.
An aborted restart is reported in the watchdog's output.

## Configuration

`config/watchdog.php`:

| Key | Env | Default | Description |
| --- | --- | --- | --- |
| `watchdog_pid_file` | `WATCHDOG_PID_FILE` | `storage/framework/watchdog.pid` | Pid file of the watchdog process itself. `WATCHDOG_SERVER_PID_FILE` is still read as a deprecated fallback. |
| `strategy` | `WATCHDOG_STRATEGY` | `backup_port` | `backup_port` or `reuse_port`. See [How a restart works](#how-a-restart-works). |
| `server_pid_file` | | `null` | Pid file written by the servers. `null` uses `server.settings.pid_file`. |
| `server_ports.main` | `WATCHDOG_MAIN_SERVER_PORT` | `9501` | Port that serves traffic. |
| `server_ports.backup` | `WATCHDOG_BACKUP_SERVER_PORT` | `9502` | Port used only during a `backup_port` restart. |
| `command.*` | | `php artisan serve` | Run as `exec {php} {artisan} {start} --port={port}` through the shell (without `--port` for `reuse_port`). It must be a single command that accepts `--port`, and custom values must be shell-escaped, as the defaults are. |
| `reload_signal` | `WATCHDOG_RELOAD_SIGNAL` | `SIGUSR2` | Signal number that triggers a restart. |
| `port_timeout` | `WATCHDOG_PORT_TIMEOUT` | `20` | Seconds to wait for a port to become free before starting a server on it. |
| `timeout` | `WATCHDOG_TIMEOUT` | `30` | Seconds for a spawned server to write its pid file, and for a server to exit after `SIGTERM` before it is sent `SIGKILL`. |
| `env_overload` | `WATCHDOG_ENV_OVERLOAD` | `true` | Start each server with the current `.env` values. See below. |

### `env_overload`

The watchdog process loads `.env` once at startup, and child processes inherit its environment. Because
Hypervel's env repository is immutable, those inherited values would take precedence over an updated
`.env`. With `env_overload` enabled, the watchdog removes every key defined in `.env`, now or when the
watchdog started, from the child's environment, so each new server reads `.env` from scratch and a key
deleted from `.env` is gone too.

As a result, **a variable defined in both the real environment (for example `docker run -e`) and
`.env` takes its value from `.env`**. Disable `env_overload` if you rely on the real environment
winning.

## Upgrading

On the first deploy of this version, **restart the watchdog process through your supervisor instead
of running `watchdog:update`**. The running watchdog still has the old code:

- **It does not hold the pid file lock.** The new `watchdog:update` therefore reports
  `Watchdog process is not running.`, exits with `1`, and sends no signal.
- **It does not handle `SIGUSR2`.** The reload signal changed from `SIGWINCH`, which terminals also
  send on every window resize and so triggered unintended restarts. `SIGUSR2` would terminate the old
  watchdog and leave its server running with no supervisor. The lock check above prevents this.

The start command now gets `--port={port}` appended. If you set `command.start` to a custom command,
make sure it accepts that option.

`WATCHDOG_SERVER_PID_FILE` is deprecated in favour of `WATCHDOG_PID_FILE`. A published
`config/watchdog.php` keeps working, but republish it to pick up the new keys and comments.

## Development

```bash
composer install
composer test               # everything
composer test:unit          # unit and feature tests, no child processes except the `process` group
composer test:integration   # runs the watchdog in subprocesses against a fixture server
```

The tests need PHP 8.4 with Swoole, `pcntl` and `posix`. Hypervel 0.4 testbench is only published
inside `hypervel/components`, so that package is a development dependency.

### Code layout

| Class | Responsibility |
| --- | --- |
| `Console\WatchdogStartCommand` | Builds a `Supervisor` through `SupervisorFactory` and maps the result to an exit code |
| `Supervisor` | Event loop: starts the first server, restarts on the reload signal, decides whether a failure is recoverable, stops everything on the way out |
| `Strategy\RestartStrategy` | How a restart keeps the port open: `BackupPortStrategy` and `ReusePortStrategy` |
| `Process\ProcessServerLauncher` | Spawns, watches and stops server processes |
| `Process\ServerCommand` | Command line and environment of a server process |
| `EventChannel` | Queue between signal handlers, server coroutines and the event loop |
| `PidFile\WatchdogPidFile`, `PidFile\ServerPidFile` | The locked watchdog pid file and the shared server pid file |
| `SharedPortConfigurator` | Adds the reuse port anchor in servers started by the watchdog |
| `AnchorRequestHandler` | Answers `404` on the anchor's port; keeps it from serving the application |

### Integration tests

`tests/Fixtures/watchdog.php` boots a testbench application and runs the watchdog commands.
`vendor/bin/testbench` cannot be used, because it installs its own `SIGTERM` and `SIGUSR2` handlers.
The servers are `tests/Fixtures/server.php`, a plain Swoole server whose flag files make it crash, hang
or ignore `SIGTERM`.

`tests/Fixtures/app.php` (`SharedPortAnchorTest`) is a real Hypervel application run through `serve`,
with the watchdog's service provider registered, so it exercises the actual anchor and reuse port setup
rather than the fixture server's emulation of it.

Some tests depend on the platform:

- `reuse_port` restarts run only on Linux; elsewhere `watchdog:start` refuses the strategy, because Swoole
  cannot bind a second server to the port.
- Only on Linux do the Swoole manager and workers exit when their master is killed.

Run the suite in a Linux container to cover both:

```bash
docker run --rm --sysctl net.ipv4.tcp_migrate_req=1 -v "$PWD":/work -w /work \
    --entrypoint php <image with PHP 8.4 and Swoole 6.2> vendor/bin/phpunit
```
