<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// Runs the image's entrypoint outside a container against stand-ins for php and frankenphp, because what is proven here is which processes it starts, supervises and stops, not the image build.
#[CoversNothing]
final class EntrypointProcessesTest extends TestCase
{
    private const string SECRET = 'a-test-key-that-is-at-least-thirty-two-bytes-long';

    private const int SECONDS_TO_WAIT = 20;

    // POSIX numbers rather than SIGKILL, SIGSTOP and SIGTERM, because those constants exist only with pcntl and the CI image has none.
    private const int KILL = 9;
    private const int STOP = 19;
    private const int CONTINUE = 18;
    private const int TERMINATE = 15;

    // The same 8 seconds as the entrypoint's grace period between SIGTERM and SIGKILL, because a stop that takes longer is cut off by Docker's 10 second stop timeout.
    private const int GRACE_SECONDS = 8;

    private const string ZOMBIE = 'Z';

    private const string CADDYFILE_REJECTED = 'parsing caddyfile tokens: wrong argument count, at Caddyfile:19';

    private Process $entrypoint;

    private string $directory;

    /** @var array<string, false|string> */
    private array $environment;

    public function testTheConsumersAndTheSchedulerRunBesideTheWebServerAlsoWithPlainPhp(): void
    {
        $this->startEntrypoint();
        $processes = $this->waitForProcesses(['web' => 1, 'async' => 2, 'scheduler_watcher' => 1]);

        $programs = array_map(
            static fn (array $arguments): string => basename($arguments[0]),
            array_filter($this->childArguments(), static fn (array $arguments): bool => in_array('messenger:consume', $arguments, true)),
        );

        self::assertSame(['web' => 1, 'async' => 2, 'scheduler_watcher' => 1], array_count_values($processes));
        self::assertSame(['php'], array_values(array_unique($programs)));
    }

    #[DataProvider('longRunningConsumers')]
    public function testAKilledConsumerIsStartedAgain(string $transport): void
    {
        $this->startEntrypoint();
        $before = $this->waitForProcesses(['web' => 1, 'async' => 2, 'scheduler_watcher' => 1]);
        $killed = (int) array_search($transport, $before, true);

        posix_kill($killed, self::KILL);
        $after = $this->waitForProcesses(['web' => 1, 'async' => 2, 'scheduler_watcher' => 1], [$killed]);

        self::assertArrayNotHasKey($killed, $after);
        self::assertStringContainsString($transport.' ended with status 137, starting it again', $this->entrypoint->getErrorOutput());
    }

    public function testTwoConsumersEndingTogetherAreBothStartedAgain(): void
    {
        $this->startEntrypoint();
        $before = $this->waitForProcesses(['web' => 1, 'async' => 2, 'scheduler_watcher' => 1]);
        $killed = array_keys($before, 'async', true);
        // Held stopped while both die, because only then are both exits in before it waits again, the order that lost one.
        $this->entrypoint->signal(self::STOP);
        foreach ($killed as $pid) {
            posix_kill($pid, self::KILL);
        }
        $this->waitUntilEnded($killed);
        $this->entrypoint->signal(self::CONTINUE);

        $after = $this->waitForProcesses(['web' => 1, 'async' => 2, 'scheduler_watcher' => 1], $killed);

        self::assertSame([], array_intersect($killed, array_keys($after)));
    }

    public function testAConsumerKilledWhileOthersAreStartedAgainIsStartedAgainToo(): void
    {
        $this->startEntrypoint();
        $before = $this->waitForProcesses(['web' => 1, 'async' => 2, 'scheduler_watcher' => 1]);
        $workers = $this->inEntrypointOrder(array_keys($before), (int) array_search('web', $before, true));
        // The entrypoint checks its children in this order, so the first one is already found alive when the last two are started again.
        $victim = (int) array_first($workers);
        $killedFirst = array_slice($workers, -2);
        $this->entrypoint->signal(self::STOP);
        foreach ($killedFirst as $pid) {
            posix_kill($pid, self::KILL);
        }
        $this->waitUntilEnded($killedFirst);
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
        $this->startEntrypoint();
        $processes = $this->waitForProcesses(['web' => 1, 'async' => 2, 'scheduler_watcher' => 1]);

        $this->entrypoint->signal(self::TERMINATE);
        $this->entrypoint->wait();

        self::assertSame(0, $this->entrypoint->getExitCode());
        self::assertSame([], array_filter(array_keys($processes), static fn (int $pid): bool => file_exists('/proc/'.$pid)));
    }

    public function testStoppingTheEntrypointBetweenStartingAChildAndRecordingItStopsThatChildToo(): void
    {
        // Bash runs a pending trap between two commands, so a SIGTERM sent by this DEBUG trap lands after "$1" & and before running[$!]=$1 of the first child, whose pid the trap notes: the order in which a stop that only knew recorded children left it running.
        $forcedOrder = <<<'BASH_WRAP'
        set -T
        trap 'if [[ "$BASH_COMMAND" == "running[\$!]=\$1" ]]; then trap - DEBUG; echo "$!" >> "$CHILD_STARTED_BEFORE_THE_STOP"; kill -TERM "$$"; fi' DEBUG
        source docker/entrypoint.sh
        BASH_WRAP;
        $this->startEntrypoint(['bash', '-c', $forcedOrder], ['CHILD_STARTED_BEFORE_THE_STOP' => $this->childrenStartedBeforeTheStopFile()]);

        $this->waitForTheEntrypointToEnd(self::GRACE_SECONDS);

        self::assertSame(0, $this->entrypoint->getExitCode(), $this->entrypoint->getErrorOutput());
        self::assertCount(1, $this->childrenStartedBeforeTheStop());
        self::assertSame([], array_filter($this->childrenStartedBeforeTheStop(), static fn (int $pid): bool => file_exists('/proc/'.$pid)));
    }

    public function testAConsumerThatDoesNotStopOnSigtermIsKilledSoTheEntrypointEnds(): void
    {
        $this->startEntrypoint();
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
        $this->startEntrypoint();
        $processes = $this->waitForProcesses(['web' => 1, 'async' => 2, 'scheduler_watcher' => 1]);

        posix_kill((int) array_search('web', $processes, true), self::KILL);
        $this->entrypoint->wait();

        self::assertSame(137, $this->entrypoint->getExitCode());
        self::assertSame([], array_filter(array_keys($processes), static fn (int $pid): bool => file_exists('/proc/'.$pid)));
    }

    public function testACaddyfileFrankenPhpRejectsStopsTheEntrypointWithTheReasonBeforeAnythingStarts(): void
    {
        $this->startEntrypoint(environment: ['PATH' => $this->stubProgramsWithARejectedCaddyfile().':'.getenv('PATH')]);
        $this->entrypoint->setTimeout(self::SECONDS_TO_WAIT);

        $this->entrypoint->wait();

        self::assertNotSame(0, $this->entrypoint->getExitCode());
        self::assertStringContainsString(self::CADDYFILE_REJECTED, $this->entrypoint->getErrorOutput());
        self::assertStringNotContainsString('started:', $this->entrypoint->getErrorOutput());
    }

    public function testAnEntrypointATestLeftStoppedStillStopsEveryProcessWhenTheTestEnds(): void
    {
        $this->startEntrypoint();
        $children = array_keys($this->waitForProcesses(['web' => 1, 'async' => 2, 'scheduler_watcher' => 1]));
        // Left stopped, because that is how a test that fails between its SIGSTOP and its SIGCONT leaves it.
        $this->entrypoint->signal(self::STOP);

        try {
            $this->stopTheEntrypoint();

            self::assertSame([], array_values(array_filter($children, $this->hasNotEnded(...))));
        } finally {
            foreach ($children as $pid) {
                posix_kill($pid, self::KILL);
            }
        }
    }

    protected function setUp(): void
    {
        // The entrypoint is started per test, not per class, because each test kills, stops or restarts its processes and so cannot share them.
        $this->directory = sys_get_temp_dir().'/intakt-entrypoint-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->directory);
        $this->environment = [
            'PATH' => $this->standInPrograms().':'.getenv('PATH'),
            // In the test's folder, because every start removes the cache it builds again, and this one runs in the checkout.
            'APP_CACHE_DIR' => $this->directory.'/cache',
            'APP_BUILD_DIR' => false,
            'MESSENGER_CONSUMERS' => '2',
            'WORKER_MEMORY_CEILING_MB' => '256',
            'APP_SECRET' => self::SECRET,
            'MERCURE_JWT_SECRET' => self::SECRET,
            'MERCURE_JWT_ISSUER' => 'http://localhost',
        ];
    }

    protected function tearDown(): void
    {
        $this->stopTheEntrypoint();
        // Killed here because an entrypoint with the defect the forced-order test proves absent ends without stopping this child, which would otherwise outlive the test.
        foreach ($this->childrenStartedBeforeTheStop() as $pid) {
            posix_kill($pid, self::KILL);
        }
        new Filesystem()->remove($this->directory);
    }

    private function stopTheEntrypoint(): void
    {
        $pid = $this->entrypoint->getPid();

        // Resumed first, because a test that failed while holding it stopped would leave it unable to act on SIGTERM, so the kill below would end it before it stops its children.
        if (null !== $pid) {
            posix_kill($pid, self::CONTINUE);
        }

        // Longer than the entrypoint's grace period, so it ends its children itself and none outlives the test.
        $this->entrypoint->stop(15);
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $environment
     */
    private function startEntrypoint(array $command = ['docker/entrypoint.sh'], array $environment = []): void
    {
        $this->entrypoint = new Process($command, dirname(__DIR__, 4), [...$this->environment, ...$environment]);
        $this->entrypoint->setTimeout(null);
        $this->entrypoint->start();
    }

    // Stand-ins that loop over sleep without exec, so /proc keeps their arguments; a bash script dies on SIGTERM and stops on SIGSTOP like the real programs, and the real start is proved by the image smoke test and CaddyfileWorkersTest.
    private function standInPrograms(): string
    {
        $programs = $this->directory.'/stubs';
        $filesystem = new Filesystem();
        $loop = "while :; do sleep 1; done\n";

        $filesystem->dumpFile($programs.'/php', "#!/bin/bash\ncase \"\$*\" in '-r '*) printf 'APP_ENV=test\\nAPP_CACHE_DIR=%s\\nAPP_BUILD_DIR=\\n' \"\$APP_CACHE_DIR\"; exit 0;; *migrate*|*cache:warmup*) exit 0;; esac\n".$loop);
        $filesystem->dumpFile($programs.'/frankenphp', "#!/bin/bash\ncase \"\$*\" in *validate*) exit 0;; esac\n".$loop);
        // The entrypoint's one second poll runs a tenth as long, so a restart takes tenths of a second; the 0.1 second steps of its stop and every other sleep keep their length, so the grace period stays 8 seconds.
        $filesystem->dumpFile($programs.'/sleep', "#!/bin/bash\n[[ \"\$*\" == 1 ]] && set -- 0.1\nexec \"\$(PATH=\${PATH#*:} command -v sleep)\" \"\$@\"\n");
        $filesystem->chmod([$programs.'/php', $programs.'/frankenphp', $programs.'/sleep'], 0755);

        return $programs;
    }

    // Stand-ins that note every start, so a web server or consumer started after the rejection shows in the output.
    private function stubProgramsWithARejectedCaddyfile(): string
    {
        $programs = $this->directory.'/rejecting-stubs';
        $filesystem = new Filesystem();
        $filesystem->dumpFile($programs.'/php', "#!/bin/bash\ncase \"\$*\" in '-r '*) printf 'APP_ENV=test\\nAPP_CACHE_DIR=%s\\nAPP_BUILD_DIR=\\n' \"\$APP_CACHE_DIR\"; exit 0;; *migrate*|*cache:warmup*) exit 0;; esac\necho \"started: php \$*\" >&2\nexec sleep 300\n");
        $filesystem->dumpFile($programs.'/frankenphp', "#!/bin/bash\ncase \"\$*\" in *validate*) echo '".self::CADDYFILE_REJECTED."' >&2; exit 1;; esac\necho \"started: frankenphp \$*\" >&2\nexec sleep 300\n");
        $filesystem->chmod([$programs.'/php', $programs.'/frankenphp'], 0755);

        return $programs;
    }

    private function childrenStartedBeforeTheStopFile(): string
    {
        return $this->directory.'/children-started-before-the-stop';
    }

    private function waitForTheEntrypointToEnd(int $seconds): void
    {
        $deadline = hrtime(true) + $seconds * 1_000_000_000;

        while (hrtime(true) < $deadline && $this->entrypoint->isRunning()) {
            usleep(10_000);
        }

        self::assertFalse($this->entrypoint->isRunning(), sprintf('The entrypoint still runs %d seconds after SIGTERM, with %d children left.', $seconds, count(array_filter($this->childrenStartedBeforeTheStop(), static fn (int $pid): bool => file_exists('/proc/'.$pid)))));
    }

    /**
     * @return list<int>
     */
    private function childrenStartedBeforeTheStop(): array
    {
        $noted = @file_get_contents($this->childrenStartedBeforeTheStopFile());

        return is_string($noted) ? array_values(array_map(intval(...), array_filter(explode("\n", $noted), static fn (string $line): bool => '' !== $line))) : [];
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

            usleep(10_000);
        }

        self::fail('The entrypoint did not start every process: '.$this->entrypoint->getOutput().$this->entrypoint->getErrorOutput());
    }

    /**
     * @param list<int> $pids
     */
    private function waitUntilEnded(array $pids): void
    {
        $deadline = hrtime(true) + self::SECONDS_TO_WAIT * 1_000_000_000;

        while (hrtime(true) < $deadline && [] !== array_filter($pids, $this->hasNotEnded(...))) {
            usleep(10_000);
        }

        self::assertSame([], array_values(array_filter($pids, $this->hasNotEnded(...))), 'A killed consumer still runs.');
    }

    // A child of the stopped entrypoint stays a zombie until the entrypoint waits for it, so a zombie has ended and its exit is waiting there.
    private function hasNotEnded(int $pid): bool
    {
        $status = @file_get_contents('/proc/'.$pid.'/stat');

        if (!is_string($status)) {
            return false;
        }

        $end = strrpos($status, ')');

        return false !== $end && self::ZOMBIE !== substr($status, $end + 2, 1);
    }

    /**
     * @param list<int> $pids
     *
     * @return list<int>
     */
    private function inEntrypointOrder(array $pids, int $web): array
    {
        sort($pids);
        // Bash orders the keys of an associative array the same way for the same keys inserted in the same order, so this is the order the entrypoint checks its children in.
        $order = new Process(['bash', '-c', 'declare -A running=(); for pid in "$@"; do running[$pid]=x; done; printf "%s\n" "${!running[@]}"', 'bash', ...array_map(strval(...), $pids)]);
        $order->mustRun();

        return array_values(array_diff(array_map(intval(...), explode("\n", trim($order->getOutput()))), [$web]));
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

            $parts = $this->withoutTheInterpreter(explode("\0", rtrim($commandLine, "\0")));

            // Left out because the entrypoint's poll sleeps in a child of its own, which it does not supervise.
            if ('sleep' !== basename($parts[0])) {
                $arguments[$child] = $parts;
            }
        }

        return $arguments;
    }

    /**
     * @param list<string> $parts
     *
     * @return list<string>
     */
    private function withoutTheInterpreter(array $parts): array
    {
        // A stand-in is a script, so the kernel starts it as its interpreter followed by its path.
        return str_starts_with($parts[1] ?? '', $this->directory) ? array_slice($parts, 1) : $parts;
    }
}
