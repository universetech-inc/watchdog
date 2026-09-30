<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Server\Event;
use Hypervel\Server\ServerInterface;
use RuntimeException;

/**
 * Server configuration for the reuse port restart strategy.
 *
 * Swoole binds the first server in its constructor, before `enable_reuse_port` from the
 * settings applies, so SO_REUSEPORT only reaches ports added afterwards. An anchor server
 * on a random loopback port is therefore placed first, which turns the real HTTP port into
 * an added port that two server instances can share.
 */
final class SharedPortConfigurator
{
    /**
     * Env variable carrying the shared port to a server started by the watchdog.
     */
    public const string ENV_PORT = 'WATCHDOG_REUSE_PORT';

    public const string ANCHOR_NAME = 'watchdog-anchor';

    /**
     * Configure the servers of a process started by the watchdog in reuse port mode.
     *
     * Runs at register time, like Hypervel Reverb's server registration, so the change
     * lands before `serve` reads `server.servers`.
     */
    public static function apply(Repository $config, int $port): void
    {
        $servers = $config->get('server.servers', []);
        $index = self::firstHttpServerIndex($servers);
        if ($index === null) {
            throw new RuntimeException('The reuse port strategy requires an HTTP server in [server.servers].');
        }

        $servers[$index]['port'] = $port;

        array_unshift($servers, [
            'name' => self::ANCHOR_NAME,
            'type' => ServerInterface::SERVER_HTTP,
            'host' => '127.0.0.1',
            'port' => 0,
            'sock_type' => SWOOLE_SOCK_TCP,
            // Its own handler: Hypervel keeps one handler instance per callback, so sharing the
            // application's would also rename the HTTP server's instance to the anchor.
            'callbacks' => [Event::ON_REQUEST => [AnchorRequestHandler::class, 'onRequest']],
        ]);

        $config->set('server.servers', $servers);
        $config->set('server.settings.enable_reuse_port', true);
    }

    /**
     * Names of the WebSocket servers, which Swoole constructs first and so would take the
     * anchor's place.
     *
     * @return list<string>
     */
    public static function webSocketServers(array $servers): array
    {
        $names = [];
        foreach ($servers as $server) {
            if (($server['type'] ?? null) === ServerInterface::SERVER_WEBSOCKET) {
                $names[] = (string) ($server['name'] ?? 'unnamed');
            }
        }

        return $names;
    }

    /**
     * Same rule as Hypervel's `serve --port`: a server without a type is an HTTP server.
     */
    private static function firstHttpServerIndex(array $servers): int|string|null
    {
        foreach ($servers as $index => $server) {
            if (($server['type'] ?? ServerInterface::SERVER_HTTP) === ServerInterface::SERVER_HTTP) {
                return $index;
            }
        }

        return null;
    }
}
