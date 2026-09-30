<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Tests;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\TestCase as BaseTestCase;
use UniverseTech\Watchdog\Signal\SignalRegistrar;
use UniverseTech\Watchdog\Tests\Fakes\FakeSignalRegistrar;
use UniverseTech\Watchdog\WatchdogServiceProvider;

/**
 * Package tests with a booted application.
 *
 * Real Swoole signal handlers cannot be removed from the PHPUnit process, so every test
 * gets a FakeSignalRegistrar.
 */
abstract class TestCase extends BaseTestCase
{
    use UsesTemporaryDirectory;

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [WatchdogServiceProvider::class];
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->instance(SignalRegistrar::class, new FakeSignalRegistrar());
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory();

        parent::tearDown();
    }
}
