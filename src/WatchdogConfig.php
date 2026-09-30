<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog;

use Hypervel\Console\Application;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use RuntimeException;
use UniverseTech\Watchdog\Strategy\StrategyType;

/**
 * Typed snapshot of the watchdog settings, with the defaults applied.
 */
final readonly class WatchdogConfig
{
    /**
     * @param string $strategy raw `watchdog.strategy` value, validated by strategyType() so that
     *                         `watchdog:update` keeps working with an invalid value
     * @param array<int|string, array<string, mixed>> $servers `server.servers`
     */
    public function __construct(
        public string $strategy,
        public int $reloadSignal,
        public string $watchdogPidFile,
        public string $serverPidFile,
        public int $mainPort,
        public int $backupPort,
        public string $php,
        public string $artisan,
        public string $startCommand,
        public int $portTimeout,
        public int $timeout,
        public bool $envOverload,
        public array $servers,
    ) {
    }

    public static function fromRepository(Repository $config, ApplicationContract $app): self
    {
        return new self(
            strategy: (string) $config->get('watchdog.strategy', StrategyType::BackupPort->value),
            reloadSignal: (int) $config->get('watchdog.reload_signal', SIGUSR2),
            watchdogPidFile: (string) $config->get('watchdog.watchdog_pid_file', $app->storagePath('framework/watchdog.pid')),
            serverPidFile: (string) ($config->get('watchdog.server_pid_file')
                ?? $config->get('server.settings.pid_file', $app->storagePath('framework/hypervel.pid'))),
            mainPort: (int) $config->get('watchdog.server_ports.main', 9501),
            backupPort: (int) $config->get('watchdog.server_ports.backup', 9502),
            php: (string) $config->get('watchdog.command.php', Application::phpBinary()),
            artisan: (string) $config->get('watchdog.command.artisan', Application::artisanBinary()),
            startCommand: (string) $config->get('watchdog.command.start', 'serve'),
            portTimeout: (int) $config->get('watchdog.port_timeout', 20),
            timeout: (int) $config->get('watchdog.timeout', 30),
            envOverload: (bool) $config->get('watchdog.env_overload', true),
            servers: (array) $config->get('server.servers', []),
        );
    }

    public function strategyType(): StrategyType
    {
        return StrategyType::tryFrom($this->strategy)
            ?? throw new RuntimeException("Unknown watchdog strategy [{$this->strategy}].");
    }
}
