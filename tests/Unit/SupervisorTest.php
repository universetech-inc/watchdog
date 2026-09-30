<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Tests\Unit;

use Closure;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testing\UnitTestCase;
use RuntimeException;
use UniverseTech\Watchdog\EventChannel;
use UniverseTech\Watchdog\PidFile\ServerPidFile;
use UniverseTech\Watchdog\PidFile\WatchdogPidFile;
use UniverseTech\Watchdog\Process\ServerLauncher;
use UniverseTech\Watchdog\Process\ServerProcess;
use UniverseTech\Watchdog\Supervisor;
use UniverseTech\Watchdog\Tests\CreatesWatchdogConfig;
use UniverseTech\Watchdog\Tests\Fakes\FakeRestartStrategy;
use UniverseTech\Watchdog\Tests\Fakes\FakeServerLauncher;
use UniverseTech\Watchdog\Tests\Fakes\FakeSignalRegistrar;
use UniverseTech\Watchdog\Tests\Fakes\InMemoryLogger;
use UniverseTech\Watchdog\Tests\UsesTemporaryDirectory;

class SupervisorTest extends UnitTestCase
{
    use CreatesWatchdogConfig;
    use UsesTemporaryDirectory;

    protected EventChannel $events;

    protected FakeServerLauncher $launcher;

    protected FakeSignalRegistrar $signals;

    protected FakeRestartStrategy $strategy;

    protected InMemoryLogger $logger;

    protected ?Channel $result = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->events = new EventChannel();
        $this->launcher = new FakeServerLauncher($this->events);
        $this->signals = new FakeSignalRegistrar();
        $this->strategy = new FakeRestartStrategy();
        $this->logger = new InMemoryLogger();
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();

        parent::tearDown();
    }

    public function testBootRegistersSignalsAndLocksThePidFile(): void
    {
        $supervisor = $this->supervisor();
        $supervisor->boot();

        $this->assertSame([SIGUSR2, SIGTERM, SIGINT], array_keys($this->signals->handlers));
        $this->assertTrue($this->watchdogPidFile()->isLocked());
        $this->assertSame(getmypid(), $this->watchdogPidFile()->readPid());

        $this->assertTrue($this->runSupervisor(fn () => $this->signals->dispatch(SIGTERM), $supervisor));
    }

    public function testFailedPreflightRegistersNothing(): void
    {
        $this->strategy->preflight = static fn () => throw new RuntimeException('unsupported');

        try {
            $this->supervisor()->boot();
            $this->fail('Boot should fail.');
        } catch (RuntimeException $e) {
            $this->assertSame('unsupported', $e->getMessage());
        }

        $this->assertSame([], $this->signals->handlers);
        $this->assertFileDoesNotExist($this->temporaryPath('watchdog.pid'));
    }

    public function testBootFailsWhileAnotherWatchdogHoldsTheLock(): void
    {
        $other = $this->watchdogPidFile();
        $other->acquire(1);

        try {
            $this->supervisor()->boot();
            $this->fail('Boot should fail.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Another watchdog is already running', $e->getMessage());
        } finally {
            $other->release();
        }

        $this->assertSame([], $this->signals->handlers, 'A refused watchdog must not install signal handlers.');
        $this->assertNull($this->events->next(0.01), 'A refused watchdog must not request a start.');
    }

    public function testInitialStartFailureStopsTheWatchdog(): void
    {
        $this->launcher->failStart[9501] = true;

        $terminated = $this->runSupervisor();

        $this->assertFalse($terminated);
        $this->assertContains('Failed to start server. [9501]', $this->logger->messages('error'));
        $this->assertFalse($this->watchdogPidFile()->isLocked());
        $this->assertFileDoesNotExist($this->temporaryPath('watchdog.pid'));
    }

    public function testReloadRestartsAndRewritesTheServerPidFile(): void
    {
        $terminated = $this->runSupervisor(function () {
            $this->signals->dispatch(SIGUSR2);
            $this->waitUntil(fn () => in_array('Server restarted successfully.', $this->logger->messages(), true));
            $this->signals->dispatch(SIGTERM);
        });

        $this->assertTrue($terminated);
        $this->assertSame(1, $this->strategy->restarts);
        $this->assertSame('1001', file_get_contents($this->temporaryPath('server.pid')));
        $this->assertSame([], $this->launcher->servers(), 'Every server is stopped on the way out.');
        $this->assertContains('Received signal [15], stopping watchdog...', $this->logger->messages('warning'));
    }

    public function testFailureBeforeTheOriginalServerStopsIsRecovered(): void
    {
        $this->strategy->restart = function (ServerProcess $current, ServerLauncher $launcher): ServerProcess {
            throw new RuntimeException('new code crashed on boot');
        };

        $terminated = $this->runSupervisor(function () {
            $this->signals->dispatch(SIGUSR2);
            $this->waitUntil(fn () => $this->strategy->restarts === 1);
            $this->signals->dispatch(SIGTERM);
        });

        $this->assertTrue($terminated);
        $this->assertContains('new code crashed on boot', $this->logger->messages('error'));
        $this->assertContains('Restart aborted, the original server (pid: [1000]) keeps running.', $this->logger->messages('warning'));
        $this->assertSame('1000', file_get_contents($this->temporaryPath('server.pid')), 'The pid file must name the original server again.');
    }

    public function testFailureAfterTheOriginalServerStoppedEndsTheWatchdog(): void
    {
        $this->strategy->restart = function (ServerProcess $current, ServerLauncher $launcher): ServerProcess {
            $launcher->stop($current);
            throw new RuntimeException('main port lost');
        };

        $terminated = $this->runSupervisor(fn () => $this->signals->dispatch(SIGUSR2));

        $this->assertFalse($terminated);
        $this->assertNotContains('Restart aborted', $this->logger->messages('warning'));
    }

    public function testFailureLeavingTwoServersEndsTheWatchdogAndStopsBoth(): void
    {
        $this->strategy->restart = function (ServerProcess $current, ServerLauncher $launcher): ServerProcess {
            $launcher->start(9502);
            throw new RuntimeException('cannot stop the original server');
        };

        $terminated = $this->runSupervisor(fn () => $this->signals->dispatch(SIGUSR2));

        $this->assertFalse($terminated);
        $this->assertSame([], $this->launcher->servers());
        $this->assertContains('stop 9501 (1000)', $this->launcher->calls);
        $this->assertContains('stop 9502 (1001)', $this->launcher->calls);
    }

    public function testCrashOfTheCurrentServerEndsTheWatchdog(): void
    {
        $terminated = $this->runSupervisor(fn () => $this->launcher->crash(array_values($this->launcher->servers())[0]));

        $this->assertFalse($terminated);
    }

    public function testExitOfAReplacedServerIsIgnored(): void
    {
        $terminated = $this->runSupervisor(function () {
            $original = array_values($this->launcher->servers())[0];
            $this->signals->dispatch(SIGUSR2);
            $this->waitUntil(fn () => $this->strategy->restarts === 1 && ! $this->launcher->isLive($original));

            $this->launcher->crash($original);
            Coroutine::sleep(0.05);
            $this->signals->dispatch(SIGTERM);
        });

        $this->assertTrue($terminated);
    }

    public function testReloadRequestsDuringARestartAreMergedIntoOne(): void
    {
        $this->strategy->restart = function (ServerProcess $current, ServerLauncher $launcher): ServerProcess {
            if ($this->strategy->restarts === 1) {
                $this->signals->dispatch(SIGUSR2);
                $this->signals->dispatch(SIGUSR2);
                $this->signals->dispatch(SIGUSR2);
            }

            $next = $launcher->start(9501, sharesPort: true);
            $launcher->stop($current);

            return $next;
        };

        $terminated = $this->runSupervisor(function () {
            $this->signals->dispatch(SIGUSR2);
            $this->waitUntil(fn () => $this->strategy->restarts === 2);
            Coroutine::sleep(0.05);
            $this->signals->dispatch(SIGTERM);
        });

        $this->assertTrue($terminated);
        $this->assertSame(2, $this->strategy->restarts);
    }

    public function testTerminateDuringARestartLetsTheRestartFinish(): void
    {
        $this->strategy->restart = function (ServerProcess $current, ServerLauncher $launcher): ServerProcess {
            $this->signals->dispatch(SIGTERM);
            $next = $launcher->start(9501, sharesPort: true);
            $launcher->stop($current);

            return $next;
        };

        $terminated = $this->runSupervisor(fn () => $this->signals->dispatch(SIGUSR2));

        $this->assertTrue($terminated);
        $this->assertContains('Server restarted successfully.', $this->logger->messages());
    }

    public function testTerminateSignalIsHandledOnce(): void
    {
        $terminated = $this->runSupervisor(function () {
            $this->signals->dispatch(SIGTERM);
            $this->signals->dispatch(SIGINT);
        });

        $this->assertTrue($terminated);
        $this->assertCount(1, $this->logger->messages('warning'));
    }

    public function testReloadAfterStoppingIsIgnored(): void
    {
        $this->runSupervisor(fn () => $this->signals->dispatch(SIGTERM));

        $this->signals->dispatch(SIGUSR2);

        $this->assertSame(0, $this->strategy->restarts);
    }

    public function testTerminateAfterTheLoopEndedRetriesStoppingServers(): void
    {
        $this->launcher->failStop[1001] = true;
        $this->strategy->restart = function (ServerProcess $current, ServerLauncher $launcher): ServerProcess {
            $launcher->start(9501, sharesPort: true);
            throw new RuntimeException('two servers left');
        };

        $this->assertFalse($this->runSupervisor(fn () => $this->signals->dispatch(SIGUSR2)));
        $this->assertContains('Failed to stop server. [9501]', $this->logger->messages('error'));

        $this->signals->dispatch(SIGTERM);

        $this->assertSame(2, count(array_keys($this->launcher->calls, 'stop 9501 (1001)', true)));
    }

    public function testTerminateWhileStoppingLeavesTheServersToTheStopInProgress(): void
    {
        $terminated = $this->runSupervisor(function () {
            $this->launcher->stopDelay = 0.2;
            $this->launcher->crash(array_values($this->launcher->servers())[0]);
            $this->waitUntil(fn () => in_array('stop 9501 (1000)', $this->launcher->calls, true));

            $this->signals->dispatch(SIGTERM);
        });

        $this->assertTrue($terminated);
        $this->assertSame(['start 9501', 'stop 9501 (1000)'], $this->launcher->calls);
    }

    public function testFailingToWriteTheServerPidFileKeepsSupervising(): void
    {
        $supervisor = $this->supervisor(new ServerPidFile($this->temporaryPath('missing/server.pid'), new Filesystem()));
        $supervisor->boot();

        $terminated = $this->runSupervisor(function () {
            $this->signals->dispatch(SIGUSR2);
            $this->waitUntil(fn () => $this->strategy->restarts === 1);
            Coroutine::sleep(0.05);
            $this->signals->dispatch(SIGTERM);
        }, $supervisor);

        $this->assertTrue($terminated);
        $this->assertContains('Failed to write server pid file [' . $this->temporaryPath('missing/server.pid') . '].', $this->logger->messages('error'));
        $this->assertContains('Server restarted successfully.', $this->logger->messages());
        $this->assertNotContains('Restart aborted', $this->logger->messages('warning'));
        $this->assertSame([], $this->launcher->servers());
    }

    public function testIdleWatchdogKeepsRunning(): void
    {
        $terminated = $this->runSupervisor(function () {
            Coroutine::sleep(1.2);
            $this->assertTrue($this->result->isEmpty(), 'The loop must survive an idle poll timeout.');
            $this->signals->dispatch(SIGTERM);
        });

        $this->assertTrue($terminated);
    }

    /**
     * Boot and run the supervisor in its own coroutine, run the scenario once the first
     * server is up, and return what run() returned.
     */
    protected function runSupervisor(?Closure $scenario = null, ?Supervisor $booted = null): bool
    {
        $supervisor = $booted ?? $this->supervisor();
        $booted ?? $supervisor->boot();

        // Wrapped in an array: a bare `false` result would be indistinguishable from a timeout.
        $this->result = new Channel(1);
        Coroutine::create(fn () => $this->result->push([$supervisor->run()]));

        $stopped = false;
        try {
            $this->waitUntil(fn () => $this->launcher->calls !== [] || ! $this->result->isEmpty());
            if ($scenario && $this->result->isEmpty()) {
                $scenario();
            }

            $result = $this->result->pop(5);
            $this->assertIsArray($result, 'The supervisor did not stop in time.');
            $stopped = true;

            return $result[0];
        } finally {
            // Never leave the loop running: the test coroutine waits for every coroutine.
            if (! $stopped) {
                $this->signals->dispatch(SIGTERM);
                $this->result->pop(5);
            }
        }
    }

    protected function waitUntil(Closure $condition, float $timeout = 5.0): void
    {
        $deadline = microtime(true) + $timeout;
        while (! $condition()) {
            if (microtime(true) > $deadline) {
                $this->fail('Condition not met in time. Log: ' . implode(' | ', $this->logger->messages()));
            }

            Coroutine::sleep(0.01);
        }
    }

    protected function supervisor(?ServerPidFile $serverPidFile = null): Supervisor
    {
        return new Supervisor(
            $this->watchdogConfig(),
            $this->strategy,
            $this->launcher,
            $this->events,
            $this->signals,
            $this->watchdogPidFile(),
            $serverPidFile ?? new ServerPidFile($this->temporaryPath('server.pid'), new Filesystem()),
            $this->logger
        );
    }

    protected function watchdogPidFile(): WatchdogPidFile
    {
        return new WatchdogPidFile($this->temporaryPath('watchdog.pid'), new Filesystem());
    }
}
