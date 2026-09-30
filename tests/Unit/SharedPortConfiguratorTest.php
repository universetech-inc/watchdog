<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Tests\Unit;

use Hypervel\Config\Repository;
use Hypervel\Server\Event;
use Hypervel\Server\ServerInterface;
use Hypervel\Testing\UnitTestCase;
use RuntimeException;
use UniverseTech\Watchdog\AnchorRequestHandler;
use UniverseTech\Watchdog\SharedPortConfigurator;

class SharedPortConfiguratorTest extends UnitTestCase
{
    public function testApplyPutsAnAnchorInFrontAndMovesTheHttpServerToThePort(): void
    {
        $callbacks = ['request' => ['HttpServer', 'onRequest']];
        $config = new Repository(['server' => [
            'servers' => [['name' => 'http', 'type' => ServerInterface::SERVER_HTTP, 'port' => 8000, 'callbacks' => $callbacks]],
            'settings' => ['worker_num' => 2],
        ]]);

        SharedPortConfigurator::apply($config, 9501);

        $servers = $config->get('server.servers');
        $this->assertCount(2, $servers);
        $this->assertSame(SharedPortConfigurator::ANCHOR_NAME, $servers[0]['name']);
        $this->assertSame('127.0.0.1', $servers[0]['host']);
        $this->assertSame(0, $servers[0]['port']);
        $this->assertSame([Event::ON_REQUEST => [AnchorRequestHandler::class, 'onRequest']], $servers[0]['callbacks']);
        $this->assertSame('http', $servers[1]['name']);
        $this->assertSame(9501, $servers[1]['port']);
        $this->assertSame($callbacks, $servers[1]['callbacks']);
        $this->assertTrue($config->get('server.settings.enable_reuse_port'));
        $this->assertSame(2, $config->get('server.settings.worker_num'));
    }

    public function testServerWithoutTypeCountsAsHttp(): void
    {
        $config = new Repository(['server' => ['servers' => [
            ['name' => 'tcp', 'type' => ServerInterface::SERVER_BASE, 'port' => 7000],
            ['name' => 'http', 'port' => 8000],
        ]]]);

        SharedPortConfigurator::apply($config, 9501);

        $servers = $config->get('server.servers');
        $this->assertSame(7000, $servers[1]['port']);
        $this->assertSame(9501, $servers[2]['port']);
    }

    public function testApplyRequiresAnHttpServer(): void
    {
        $config = new Repository(['server' => ['servers' => [
            ['name' => 'tcp', 'type' => ServerInterface::SERVER_BASE, 'port' => 7000],
        ]]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('The reuse port strategy requires an HTTP server in [server.servers].');

        SharedPortConfigurator::apply($config, 9501);
    }

    public function testWebSocketServers(): void
    {
        $this->assertSame(['reverb', 'unnamed'], SharedPortConfigurator::webSocketServers([
            ['name' => 'http', 'type' => ServerInterface::SERVER_HTTP],
            ['name' => 'reverb', 'type' => ServerInterface::SERVER_WEBSOCKET],
            ['type' => ServerInterface::SERVER_WEBSOCKET],
            ['name' => 'untyped'],
        ]));
    }
}
