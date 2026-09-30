<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Tests\Unit;

use UniverseTech\Watchdog\Strategy\BackupPortStrategy;
use UniverseTech\Watchdog\Strategy\ReusePortStrategy;
use UniverseTech\Watchdog\SupervisorFactory;
use UniverseTech\Watchdog\Tests\Fakes\InMemoryLogger;
use UniverseTech\Watchdog\Tests\TestCase;

class SupervisorFactoryTest extends TestCase
{
    public function testStrategyFollowsTheConfig(): void
    {
        $this->assertInstanceOf(BackupPortStrategy::class, $this->factory()->createStrategy(new InMemoryLogger()));

        $this->app->make('config')->set('watchdog.strategy', 'reuse_port');

        $this->assertInstanceOf(ReusePortStrategy::class, $this->factory()->createStrategy(new InMemoryLogger()));
    }

    protected function factory(): SupervisorFactory
    {
        return $this->app->make(SupervisorFactory::class);
    }
}
