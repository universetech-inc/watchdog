<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Process;

use Dotenv\Dotenv;
use UniverseTech\Watchdog\Strategy\RestartStrategy;
use UniverseTech\Watchdog\WatchdogConfig;

/**
 * Command line and environment of a server process.
 */
class ServerCommand
{
    /**
     * Keys of the env file when the watchdog started, whose values the children inherit.
     *
     * @var list<string>
     */
    protected array $inheritedKeys = [];

    public function __construct(
        protected WatchdogConfig $config,
        protected RestartStrategy $strategy,
        protected string $environmentFile
    ) {
        if ($config->envOverload) {
            $this->inheritedKeys = array_keys($this->loadEnvironmentFile());
        }
    }

    /**
     * `exec` replaces the intermediate shell, so the spawned process is the server itself
     * and signals sent to it reach the server.
     */
    public function command(int $port): string
    {
        $command = "exec {$this->config->php} {$this->config->artisan} {$this->config->startCommand}";
        $arguments = $this->strategy->serverArguments($port);

        return $arguments === '' ? $command : "{$command} {$arguments}";
    }

    /**
     * @return array<string, false|string> `false` removes a variable inherited from this process
     */
    public function environment(int $port): array
    {
        $environment = [
            'FORCE_COLOR' => 'true',
            'TERM' => 'xterm-256color',
            'HTTP_SERVER_PORT' => (string) $port,
            ...$this->strategy->serverEnvironment($port),
        ];

        if (! $this->config->envOverload) {
            return $environment;
        }

        // Keys removed from the env file since then would otherwise keep their old value.
        $keys = [...$this->inheritedKeys, ...array_keys($this->loadEnvironmentFile())];

        return array_merge(array_fill_keys($keys, false), $environment);
    }

    /**
     * Read the keys of the current env file without touching this process's environment.
     *
     * The env repository in Hypervel 0.4 is immutable, so values inherited from this
     * process would otherwise take precedence over the updated env file in the child.
     */
    protected function loadEnvironmentFile(): array
    {
        return Dotenv::createArrayBacked(dirname($this->environmentFile), basename($this->environmentFile))
            ->safeLoad();
    }
}
