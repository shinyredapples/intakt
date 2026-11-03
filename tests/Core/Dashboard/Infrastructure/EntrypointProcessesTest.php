<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

// Runs the image's entrypoint outside a container, because what is proven here is which processes it starts and starts again, not the image build.
#[Group('hub')]
#[CoversNothing]
final class EntrypointProcessesTest extends TestCase
{
    private const string SECRET = 'a-test-key-that-is-at-least-thirty-two-bytes-long';

    // Longer than the workers test waits, because the consumers boot the kernel after the migration.
    private const int SECONDS_TO_WAIT = 90;

    // POSIX numbers rather than SIGKILL, SIGSTOP and SIGTERM, because those constants exist only with pcntl and the CI image has none.
    private const int KILL = 9;
    private const int STOP = 19;
    private const int CONTINUE = 18;
    private const int TERMINATE = 15;

    private Process $entrypoint;

    private string $directory;

    protected function setUp(): void
    {
        if (null === new ExecutableFinder()->find('frankenphp')) {
            self::markTestSkipped('The hub tests need the frankenphp binary, see docs/architecture/requirements.md.');
        }

        $this->directory = sys_get_temp_dir().'/intakt-entrypoint-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->directory);
        $port = (string) $this->freePort();
        $this->entrypoint = new Process(['docker/entrypoint.sh'], dirname(__DIR__, 4), [
            'SERVER_PORT' => $port,
            'DASHBOARD_PORT' => $port,
            'CADDY_ADMIN' => 'localhost:'.$this->freePort(),
            'DATABASE_URL' => 'sqlite:///'.$this->directory.'/intakt.db',
            'MESSENGER_TRANSPORT_DSN' => 'doctrine://default',
            'MESSENGER_CONSUMERS' => '2',
            'APP_SECRET' => self::SECRET,
            'MERCURE_JWT_SECRET' => self::SECRET,
            'MERCURE_JWT_ISSUER' => 'http://localhost:'.$port,
            'MERCURE_BOLT_PATH' => $this->directory.'/hub.db',
        ]);
        $this->entrypoint->setTimeout(null);
        $this->entrypoint->start();
    }

    protected function tearDown(): void
    {
        // Longer than the entrypoint's grace period, so it ends its children itself and none outlives the test.
        $this->entrypoint->stop(15);
        new Filesystem()->remove($this->directory);
    }

    public function testTheConsumersAndTheSchedulerRunBesideTheWebServer(): void
    {
        $processes = $this->waitForProcesses(['web' => 1, 'async' => 2, 'scheduler_watcher' => 1]);

        self::assertSame(['web' => 1, 'async' => 2, 'scheduler_watcher' => 1], array_count_values($processes));
    }

    public function testTheConsumersAndTheSchedulerRunWithPlainPhp(): void
    {
        $this->waitForProcesses(['web' => 1, 'async' => 2, 'scheduler_watcher' => 1]);

        $programs = array_map(
            static fn (array $arguments): string => basename($arguments[0]),
            array_filter($this->childArguments(), static fn (array $arguments): bool => in_array('messenger:consume', $arguments, true)),
        );

        self::assertSame(['php'], array_values(array_unique($programs)));
    }

    #[DataProvider('longRunningConsumers')]
    public function testAKilledConsumerIsStartedAgain(string $transport): void
    {
        $before = $this->waitForProcesses(['web' => 1, 'async' => 2, 'scheduler_watcher' => 1]);
        $killed = (int) array_search($transport, $before, true);

        posix_kill($killed, self::KILL);
        $after = $this->waitForProcesses(['web' => 1, 'async' => 2, 'scheduler_watcher' => 1], [$killed]);

        self::assertArrayNotHasKey($killed, $after);
        self::assertStringContainsString($transport.' ended with status 137, starting it again', $this->entrypoint->getErrorOutput());
    }

    public function testTwoConsumersEndingTogetherAreBothStartedAgain(): void
    {
        $before = $this->waitForProcesses(['web' => 1, 'async' => 2, 'scheduler_watcher' => 1]);
        $killed = array_keys($before, 'async', true);
        // Held stopped while both die, because only then are both exits in before it waits again, the order that lost one.
        $this->entrypoint->signal(self::STOP);
        foreach ($killed as $pid) {
            posix_kill($pid, self::KILL);
        }
        usleep(200_000);
        $this->entrypoint->signal(self::CONTINUE);

        $after = $this->waitForProcesses(['web' => 1, 'async' => 2, 'scheduler_watcher' => 1], $killed);

        self::assertSame([], array_intersect($killed, array_keys($after)));
    }

    public function testAConsumerKilledWhileOthersAreStartedAgainIsStartedAgainToo(): void
    {
        $before = $this->waitForProcesses(['web' => 1, 'async' => 2, 'scheduler_watcher' => 1]);
        $workers = $this->inEntrypointOrder(array_keys($before), (int) array_search('web', $before, true));
        // The entrypoint checks its children in this order, so the first one is already found alive when the last two are started again.
        $victim = array_first($workers);
        $killedFirst = array_slice($workers, -2);
        $this->entrypoint->signal(self::STOP);
        foreach ($killedFirst as $pid) {
            posix_kill($pid, self::KILL);
        }
        usleep(200_000);
        $this->entrypoint->signal(self::CONTINUE);
        $this->waitForErrorOutput('starting it again', 2);

        posix_kill($victim, self::KILL);
        $after = $this->waitForProcesses(['web' => 1, 'async' => 2, 'scheduler_watcher' => 1], [$victim, ...$killedFirst]);

        self::assertSame([], array_intersect([$victim, ...$killedFirst], array_keys($after)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function longRunningConsumers(): iterable
    {
        yield 'a queue consumer' => ['async'];
        yield 'the scheduler' => ['scheduler_watcher'];
    }

    public function testStoppingTheEntrypointStopsEveryProcess(): void
    {
        $processes = $this->waitForProcesses(['web' => 1, 'async' => 2, 'scheduler_watcher' => 1]);

        $this->entrypoint->signal(self::TERMINATE);
        $this->entrypoint->wait();

        self::assertSame(0, $this->entrypoint->getExitCode());
        self::assertSame([], array_filter(array_keys($processes), static fn (int $pid): bool => file_exists('/proc/'.$pid)));
    }

    public function testAConsumerThatDoesNotStopOnSigtermIsKilledSoTheEntrypointEnds(): void
    {
        $processes = $this->waitForProcesses(['web' => 1, 'async' => 2, 'scheduler_watcher' => 1]);
        // A stopped process cannot act on SIGTERM, like a consumer whose SIGTERM arrived before its worker existed (upstream/messenger-consume-ignores-early-sigterm).
        posix_kill((int) array_search('async', $processes, true), self::STOP);
        // A timeout, so an entrypoint without the grace period fails here instead of waiting for that consumer forever.
        $this->entrypoint->setTimeout(self::SECONDS_TO_WAIT * 2);

        $this->entrypoint->signal(self::TERMINATE);
        $this->entrypoint->wait();

        self::assertSame(0, $this->entrypoint->getExitCode());
        self::assertSame([], array_filter(array_keys($processes), static fn (int $pid): bool => file_exists('/proc/'.$pid)));
    }

    public function testTheWebServerEndingStopsTheOthersSoTheContainerIsRestarted(): void
    {
        $processes = $this->waitForProcesses(['web' => 1, 'async' => 2, 'scheduler_watcher' => 1]);

        posix_kill((int) array_search('web', $processes, true), self::KILL);
        $this->entrypoint->wait();

        self::assertSame(137, $this->entrypoint->getExitCode());
        self::assertSame([], array_filter(array_keys($processes), static fn (int $pid): bool => file_exists('/proc/'.$pid)));
    }

    /**
     * @param array<string, int> $expected
     * @param list<int>          $gone
     *
     * @return array<int, string>
     */
    private function waitForProcesses(array $expected, array $gone = []): array
    {
        $deadline = hrtime(true) + self::SECONDS_TO_WAIT * 1_000_000_000;

        while (hrtime(true) < $deadline && $this->entrypoint->isRunning()) {
            $processes = array_map($this->roleOf(...), $this->childArguments());
            $counted = array_count_values($processes);
            ksort($counted);
            ksort($expected);

            if ($counted === $expected && [] === array_intersect($gone, array_keys($processes))) {
                return $processes;
            }

            usleep(200_000);
        }

        self::fail('The entrypoint did not start every process: '.$this->entrypoint->getOutput().$this->entrypoint->getErrorOutput());
    }

    private function waitForErrorOutput(string $line, int $times): void
    {
        $deadline = hrtime(true) + self::SECONDS_TO_WAIT * 1_000_000_000;

        while (hrtime(true) < $deadline && substr_count($this->entrypoint->getErrorOutput(), $line) < $times) {
            usleep(10_000);
        }

        self::assertGreaterThanOrEqual($times, substr_count($this->entrypoint->getErrorOutput(), $line), $this->entrypoint->getErrorOutput());
    }

    /**
     * The same keys inserted in the same order give bash's associative array the order the entrypoint checks its children in.
     *
     * @param list<int> $pids
     *
     * @return list<int>
     */
    private function inEntrypointOrder(array $pids, int $web): array
    {
        sort($pids);
        $order = new Process(['bash', '-c', 'declare -A running=(); for pid in "$@"; do running[$pid]=x; done; printf "%s\n" "${!running[@]}"', 'bash', ...array_map(strval(...), $pids)]);
        $order->mustRun();

        return array_values(array_diff(array_map(intval(...), explode("\n", trim($order->getOutput()))), [$web]));
    }

    /**
     * @param list<string> $arguments
     */
    private function roleOf(array $arguments): string
    {
        if ('frankenphp' === basename($arguments[0])) {
            return 'web';
        }

        $consume = array_search('messenger:consume', $arguments, true);

        return false === $consume ? 'other' : ($arguments[$consume + 1] ?? 'other');
    }

    /**
     * @return array<int, list<string>>
     */
    private function childArguments(): array
    {
        $pid = $this->entrypoint->getPid();
        $children = null === $pid ? false : @file_get_contents(sprintf('/proc/%d/task/%d/children', $pid, $pid));
        $arguments = [];

        foreach (array_map(intval(...), array_filter(explode(' ', trim((string) $children)), static fn (string $part): bool => '' !== $part)) as $child) {
            $commandLine = @file_get_contents('/proc/'.$child.'/cmdline');

            if (!is_string($commandLine) || '' === $commandLine) {
                continue;
            }

            $parts = explode("\0", rtrim($commandLine, "\0"));

            // The entrypoint's poll sleeps in a child of its own, which is no process it supervises.
            if ('sleep' !== basename($parts[0])) {
                $arguments[$child] = $parts;
            }
        }

        return $arguments;
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
        self::assertNotFalse($socket);
        $port = (int) parse_url('tcp://'.stream_socket_get_name($socket, false), PHP_URL_PORT);
        fclose($socket);

        return $port;
    }
}
