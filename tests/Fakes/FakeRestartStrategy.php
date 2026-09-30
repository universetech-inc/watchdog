<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Tests\Fakes;

use Closure;
use UniverseTech\Watchdog\Process\ServerLauncher;
use UniverseTech\Watchdog\Process\ServerProcess;
use UniverseTech\Watchdog\Strategy\RestartStrategy;

/**
 * Restarts on the main port of the given launcher calls, or runs a scripted restart.
 */
class FakeRestartStrategy implements RestartStrategy
{
    public int $restarts = 0;

    /**
     * @param null|Closure(ServerProcess, ServerLauncher): ServerProcess $restart
     */
    public function __construct(
        public int $mainPort = 9501,
        public ?Closure $restart = null,
        public ?Closure $preflight = null
    ) {
    }

    public function preflight(): void
    {
        if ($this->preflight) {
            ($this->preflight)();
        }
    }

    public function serverArguments(int $port): string
    {
        return '';
    }

    public function serverEnvironment(int $port): array
    {
        return [];
    }

    public function restart(ServerProcess $current, ServerLauncher $launcher): ServerProcess
    {
        $this->restarts++;

        if ($this->restart) {
            return ($this->restart)($current, $launcher);
        }

        $next = $launcher->start($this->mainPort, sharesPort: true);
        $launcher->stop($current);

        return $next;
    }
}
