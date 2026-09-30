<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog;

use Psr\Log\LoggerInterface;
use Throwable;
use UniverseTech\Watchdog\PidFile\ServerPidFile;
use UniverseTech\Watchdog\PidFile\WatchdogPidFile;
use UniverseTech\Watchdog\Process\ServerLauncher;
use UniverseTech\Watchdog\Process\ServerProcess;
use UniverseTech\Watchdog\Signal\SignalRegistrar;
use UniverseTech\Watchdog\Strategy\RestartStrategy;

/**
 * Keeps one server on the main port and replaces it on every reload signal.
 */
class Supervisor
{
    /**
     * Signals that stop the watchdog together with the servers it supervises.
     */
    public const array TERMINATE_SIGNALS = [SIGTERM, SIGINT];

    /**
     * Seconds between event checks. The timeout also keeps a timer on the event loop,
     * so Swoole does not treat an idle watchdog as a coroutine deadlock.
     */
    protected const float EVENT_POLL_INTERVAL = 1.0;

    /**
     * Seconds a signal handler may wait to enqueue an event before dropping it.
     */
    protected const float EVENT_PUSH_TIMEOUT = 0.001;

    protected bool $terminating = false;

    protected bool $stopped = false;

    protected bool $stoppingServers = false;

    /**
     * The server on the main port.
     */
    protected ?ServerProcess $current = null;

    public function __construct(
        protected WatchdogConfig $config,
        protected RestartStrategy $strategy,
        protected ServerLauncher $launcher,
        protected EventChannel $events,
        protected SignalRegistrar $signals,
        protected WatchdogPidFile $watchdogPidFile,
        protected ServerPidFile $serverPidFile,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * The lock comes before the signal handlers, which cannot be removed: a watchdog refused
     * for another one already running must leave none behind.
     */
    public function boot(): void
    {
        $this->strategy->preflight();
        $this->watchdogPidFile->acquire(posix_getpid());
        $this->events->request();
        $this->registerSignals();
    }

    /**
     * Serve until a terminate signal or a failure the watchdog cannot recover from.
     *
     * @return bool whether a terminate signal stopped the watchdog
     */
    public function run(): bool
    {
        try {
            while ($this->waitForRequest()) {
                try {
                    if ($this->current === null) {
                        $this->current = $this->launcher->start($this->config->mainPort);
                    } else {
                        $this->restart();
                    }
                } catch (Throwable $e) {
                    $this->logger->error($e->getMessage());

                    if (! $this->canRecover()) {
                        break;
                    }

                    // Starting the new server cleared the pid file the original server shares.
                    $this->restoreServerPidFile();
                    $this->logger->warning("Restart aborted, the original server (pid: [{$this->current->pid}]) keeps running.");
                }
            }
        } finally {
            $this->shutdown();
        }

        return $this->terminating;
    }

    protected function restart(): void
    {
        $this->logger->info('Restarting server...');

        $this->current = $this->strategy->restart($this->current, $this->launcher);

        // Stopping the other server unlinked the pid file it shared with the current one.
        $this->restoreServerPidFile();

        $this->logger->info('Server restarted successfully.');
    }

    /**
     * The pid file only informs other tools, so failing to write it must not end the
     * supervision of a running server.
     */
    protected function restoreServerPidFile(): void
    {
        try {
            $this->serverPidFile->write($this->current->pid);
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage());
        }
    }

    /**
     * Wait for the next start/restart request.
     *
     * Returns false once the watchdog should stop: a supervised server stopped
     * unexpectedly or a terminate signal was received.
     */
    protected function waitForRequest(): bool
    {
        while (! $this->terminating) {
            $event = $this->events->next(self::EVENT_POLL_INTERVAL);
            if ($event === null) {
                continue;
            }

            if ($event instanceof ServerProcess) {
                // A server that a restart has already replaced needs no action.
                if ($this->launcher->isLive($event)) {
                    return false;
                }

                continue;
            }

            return $event === true && ! $this->terminating;
        }

        return false;
    }

    /**
     * A failure is recoverable only while the original server still serves the main
     * port and is the only server left, i.e. the new server never came up.
     */
    protected function canRecover(): bool
    {
        return $this->current !== null
            && ! $this->current->stopping
            && ! $this->current->exited
            && count($this->launcher->servers()) === 1;
    }

    /**
     * Handlers stay registered until the process exits, so signals arriving while a
     * restart is in progress are not lost.
     */
    protected function registerSignals(): void
    {
        $this->signals->register($this->config->reloadSignal, function () {
            if ($this->terminating || $this->stopped) {
                return;
            }

            // A full channel means a restart is already pending.
            $this->events->request(self::EVENT_PUSH_TIMEOUT);
        });

        foreach (self::TERMINATE_SIGNALS as $signal) {
            $this->signals->register($signal, function (int $signo) {
                if ($this->terminating) {
                    return;
                }

                $this->terminating = true;
                $this->logger->warning("Received signal [{$signo}], stopping watchdog...");

                // The event loop has already ended, but a server that failed to stop keeps
                // this process alive, so try again.
                if ($this->stopped) {
                    $this->stopServers();
                    return;
                }

                $this->events->wake(self::EVENT_PUSH_TIMEOUT);
            });
        }
    }

    /**
     * Stop every server, whatever the reason the loop ended. A server left running would
     * keep this process alive unsupervised and hold its port against the next watchdog.
     */
    protected function shutdown(): void
    {
        $this->stopped = true;

        $this->stopServers();

        // Release server coroutines still blocked on reporting an exit.
        $this->events->close();
        $this->watchdogPidFile->release();
    }

    /**
     * A terminate signal arriving while the servers are being stopped leaves it to the
     * stop in progress, rather than signalling and waiting for the same servers twice.
     */
    protected function stopServers(): void
    {
        if ($this->stoppingServers) {
            return;
        }

        $this->stoppingServers = true;
        try {
            foreach ($this->launcher->servers() as $server) {
                try {
                    $this->launcher->stop($server);
                } catch (Throwable $e) {
                    $this->logger->error($e->getMessage());
                }
            }
        } finally {
            $this->stoppingServers = false;
        }
    }
}
