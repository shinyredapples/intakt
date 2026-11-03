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

        posix_kill($killed, SIGKILL);
        $after = $this->waitForProcesses(['web' => 1, 'async' => 2, 'scheduler_watcher' => 1], $killed);

        self::assertArrayNotHasKey($killed, $after);
        self::assertStringContainsString($transport.' ended with status 137, starting it again', $this->entrypoint->getErrorOutput());
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

        $this->entrypoint->signal(SIGTERM);
        $this->entrypoint->wait();

        self::assertSame(0, $this->entrypoint->getExitCode());
        self::assertSame([], array_filter(array_keys($processes), static fn (int $pid): bool => file_exists('/proc/'.$pid)));
    }

    public function testAConsumerThatDoesNotStopOnSigtermIsKilledSoTheEntrypointEnds(): void
    {
        $processes = $this->waitForProcesses(['web' => 1, 'async' => 2, 'scheduler_watcher' => 1]);
        // A stopped process cannot act on SIGTERM, like a consumer whose SIGTERM arrived before its worker existed (upstream/messenger-consume-ignores-early-sigterm).
        posix_kill((int) array_search('async', $processes, true), SIGSTOP);
        // A timeout, so an entrypoint without the grace period fails here instead of waiting for that consumer forever.
        $this->entrypoint->setTimeout(self::SECONDS_TO_WAIT * 2);

        $this->entrypoint->signal(SIGTERM);
        $this->entrypoint->wait();

        self::assertSame(0, $this->entrypoint->getExitCode());
        self::assertSame([], array_filter(array_keys($processes), static fn (int $pid): bool => file_exists('/proc/'.$pid)));
    }

    public function testTheWebServerEndingStopsTheOthersSoTheContainerIsRestarted(): void
    {
        $processes = $this->waitForProcesses(['web' => 1, 'async' => 2, 'scheduler_watcher' => 1]);

        posix_kill((int) array_search('web', $processes, true), SIGKILL);
        $this->entrypoint->wait();

        self::assertSame(137, $this->entrypoint->getExitCode());
        self::assertSame([], array_filter(array_keys($processes), static fn (int $pid): bool => file_exists('/proc/'.$pid)));
    }

    /**
     * @param array<string, int> $expected
     *
     * @return array<int, string>
     */
    private function waitForProcesses(array $expected, ?int $gone = null): array
    {
        $deadline = hrtime(true) + self::SECONDS_TO_WAIT * 1_000_000_000;

        while (hrtime(true) < $deadline && $this->entrypoint->isRunning()) {
            $processes = array_map($this->roleOf(...), $this->childArguments());
            $counted = array_count_values($processes);
            ksort($counted);
            ksort($expected);

            if ($counted === $expected && !isset($processes[$gone ?? 0])) {
                return $processes;
            }

            usleep(200_000);
        }

        self::fail('The entrypoint did not start every process: '.$this->entrypoint->getOutput().$this->entrypoint->getErrorOutput());
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

            if (is_string($commandLine) && '' !== $commandLine) {
                $arguments[$child] = explode("\0", rtrim($commandLine, "\0"));
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
