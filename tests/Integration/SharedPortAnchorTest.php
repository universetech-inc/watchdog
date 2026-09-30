<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use Symfony\Component\Process\Process;

/**
 * Runs Hypervel's own `serve` (tests/Fixtures/app.php) with the reuse port setup applied by
 * the service provider, which the fixture server used by the other tests only emulates.
 */
#[Group('integration')]
#[Large]
class SharedPortAnchorTest extends IntegrationTestCase
{
    public function testAnchorAnswersNotFoundWhileTheApplicationServesTheSharedPort(): void
    {
        $app = $this->startApp();
        $response = $this->waitUntilAppServes($app);

        $this->assertMatchesRegularExpression('/^\d+$/', $response['body']);

        $anchorPorts = $this->anchorPorts();
        $this->assertCount(1, $anchorPorts);
        $this->assertNotSame($this->mainPort, $anchorPorts[0]);
        $this->assertSame(['status' => 404, 'body' => ''], $this->get($anchorPorts[0]));
        $this->assertStringNotContainsString('will be replaced', $this->outputOf($app));

        $this->signal($app, SIGTERM);
        $this->waitForExit($app);
        $this->assertNoLeftoverProcesses();
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function testSecondServerJoinsTheSharedPort(): void
    {
        $first = $this->startApp();
        $this->waitUntilAppServes($first);
        $second = $this->startApp();
        $this->waitUntil(fn () => count($this->anchorPorts()) === 2, $second);

        // The kernel spreads new connections over both listeners.
        $pids = [];
        for ($i = 0; $i < 200 && count($pids) < 2; ++$i) {
            $pids[$this->get($this->mainPort)['body'] ?? ''] = true;
        }

        $this->assertCount(2, $pids, 'Both servers must serve the shared port.');
        $this->assertTrue($first->isRunning() && $second->isRunning());

        $this->signal($first, SIGTERM);
        $this->signal($second, SIGTERM);
        $this->waitForExit($first);
        $this->waitForExit($second);
        $this->assertNoLeftoverProcesses();
    }

    protected function startApp(): Process
    {
        $process = new Process(
            [PHP_BINARY, dirname(__DIR__) . '/Fixtures/app.php', 'serve'],
            null,
            [
                'WATCHDOG_TEST_STATE_DIR' => $this->stateDir,
                'WATCHDOG_REUSE_PORT' => (string) $this->mainPort,
            ],
        );
        $process->setTimeout(null);
        $process->start();
        $this->processes[] = $process;

        return $process;
    }

    /**
     * @return array{status: int, body: string}
     */
    protected function waitUntilAppServes(Process $app): array
    {
        $this->waitUntil(fn () => ($this->get($this->mainPort)['status'] ?? null) === 200, $app);

        return $this->get($this->mainPort);
    }

    protected function waitUntil(callable $condition, Process $process): void
    {
        $deadline = microtime(true) + self::TIMEOUT;
        while (! $condition()) {
            if (microtime(true) > $deadline || ! $process->isRunning()) {
                $this->fail("Condition not met in time:\n" . $this->outputOf($process));
            }

            usleep(self::POLL_INTERVAL);
        }
    }

    /**
     * @return null|array{status: int, body: string}
     */
    protected function get(int $port): ?array
    {
        $context = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
        $body = @file_get_contents("http://127.0.0.1:{$port}/", false, $context);
        if ($body === false) {
            return null;
        }

        preg_match('/^HTTP\/\S+ (\d{3})/', http_get_last_response_headers()[0] ?? '', $matches);

        return ['status' => (int) ($matches[1] ?? 0), 'body' => $body];
    }

    /**
     * @return list<int> ports of the Swoole main servers, which are the anchors
     */
    protected function anchorPorts(): array
    {
        $files = glob($this->stateDir . '/main-port-*') ?: [];

        return array_map(static fn (string $file): int => (int) file_get_contents($file), $files);
    }
}
