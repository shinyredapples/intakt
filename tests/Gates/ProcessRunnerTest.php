<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\GitCall;
use Intakt\Gates\HardenedGitCall;
use Intakt\Gates\ProcessGroup;
use Intakt\Gates\ProcessRunner;
use Intakt\Gates\TimeLimit;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProcessRunner::class)]
#[UsesClass(ProcessGroup::class)]
#[UsesClass(TimeLimit::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(HardenedGitCall::class)]
final class ProcessRunnerTest extends TestCase
{
    /** @var list<string> */
    private array $markers = [];

    public function testConcurrentCommandsKeepTheirOwnOutputEvenWhenItOverflowsThePipes(): void
    {
        $results = new ProcessRunner()->runConcurrently([
            'first' => [['php', '-r', 'echo str_repeat("a", 300000); fwrite(STDERR, str_repeat("b", 300000)); exit(3);'], []],
            'second' => [['php', '-r', 'echo str_repeat("c", 300000), getenv("PASS");'], ['PASS' => 'second']],
        ], sys_get_temp_dir());

        self::assertSame(3, $results['first']->exitCode);
        self::assertSame(str_repeat('a', 300000), $results['first']->output);
        self::assertSame(str_repeat('b', 300000), $results['first']->errors);
        self::assertTrue($results['second']->succeeded());
        self::assertSame(str_repeat('c', 300000).'second', $results['second']->output);
    }

    public function testGitIgnoresAnFsmonitorCommandOfTheRepository(): void
    {
        $sandbox = Sandbox::empty();
        $marker = $sandbox->root.'/fsmonitor-ran';
        $sandbox->write('monitor.sh', "#!/bin/sh\ntouch ".$marker."\n");
        chmod($sandbox->root.'/monitor.sh', 0o755);
        $sandbox->git('init', '--quiet');
        $sandbox->git('config', 'core.fsmonitor', $sandbox->root.'/monitor.sh');

        $result = new ProcessRunner()->git(['status', '--short'], $sandbox->root);

        self::assertTrue($result->succeeded(), $result->errors);
        self::assertFileDoesNotExist($marker);
    }

    public function testACommandThatCannotStartIsReportedWithoutStoppingTheOthers(): void
    {
        $results = new ProcessRunner()->runConcurrently([
            'missing' => [['/nonexistent/intakt-command'], []],
            'present' => [['php', '-r', 'echo "ran";'], []],
        ], sys_get_temp_dir());

        self::assertFalse($results['missing']->succeeded());
        self::assertSame('ran', $results['present']->output);
    }

    public function testACommandWithinItsTimeKeepsItsOutputAndExitCode(): void
    {
        $result = new ProcessRunner()->runWithin(['php', '-r', 'echo "out"; fwrite(STDERR, "err"); exit(4);'], sys_get_temp_dir(), 10);

        self::assertSame(4, $result->exitCode);
        self::assertSame('out', $result->output);
        self::assertSame('err', $result->errors);
    }

    public function testATimedOutCommandDiscardsItsPartialOutput(): void
    {
        $printed = $this->markerFile();

        $result = new ProcessRunner()->runWithin(['php', '-r', 'echo "partial"; fwrite(STDERR, "partial"); touch($argv[1]); sleep(30);', $printed], sys_get_temp_dir(), 0.3);

        self::assertFileExists($printed, 'The command was stopped before it printed');
        self::assertSame(ProcessRunner::TIMED_OUT, $result->exitCode);
        self::assertSame('', $result->output);
        self::assertSame('php timed out after 0.3 seconds', $result->errors);
    }

    public function testACommandPastAFractionalTimeIsStoppedAndNamesItsLimit(): void
    {
        $started = (hrtime(true) / 1_000_000_000);

        $result = new ProcessRunner()->runWithin(['php', '-r', 'echo "partial"; sleep(30);'], sys_get_temp_dir(), 0.3);

        self::assertSame(ProcessRunner::TIMED_OUT, $result->exitCode);
        self::assertSame('php timed out after 0.3 seconds', $result->errors);
        self::assertLessThan(10, (hrtime(true) / 1_000_000_000) - $started);
    }

    public function testALimitShorterThanTheStartItselfTimesOutAndNamesTheLimit(): void
    {
        // A microsecond is always shorter than starting a process, so the limit has passed when the start ends.
        $result = new ProcessRunner()->runWithin(['php', '-r', 'echo "ran";'], sys_get_temp_dir(), 1.0E-6);

        self::assertSame(ProcessRunner::TIMED_OUT, $result->exitCode);
        self::assertSame('php timed out after 1.0E-6 seconds', $result->errors);
    }

    public function testAConcurrentRunWithALimitShorterThanTheStartItselfTimesOutAndNamesTheLimit(): void
    {
        $results = new ProcessRunner()->runConcurrently(['command' => [['php', '-r', 'echo "ran";'], []]], sys_get_temp_dir(), 1.0E-6);

        self::assertSame(ProcessRunner::TIMED_OUT, $results['command']->exitCode);
        self::assertSame('php timed out after 1.0E-6 seconds', $results['command']->errors);
    }

    public function testALimitThatIsNotFiniteInNanosecondsIsRefusedBeforeTheCommandRuns(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProcessRunner()->runWithin(['php', '-r', 'echo "ran";'], sys_get_temp_dir(), PHP_FLOAT_MAX);
    }

    public function testAConcurrentRunWithALimitThatIsNotFiniteInNanosecondsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProcessRunner()->runConcurrently(['command' => [['php', '-r', 'echo "ran";'], []]], sys_get_temp_dir(), PHP_FLOAT_MAX);
    }

    public function testACommandPastTheOverallTimeoutOfAConcurrentRunFailsAloneWhileTheOthersKeepTheirResult(): void
    {
        $printed = $this->markerFile();

        $results = new ProcessRunner()->runConcurrently([
            'slow' => [['php', '-r', 'echo "partial"; touch($argv[1]); sleep(30);', $printed], []],
            'quick' => [['php', '-r', 'echo "done";'], []],
        ], sys_get_temp_dir(), 0.5);

        self::assertFileExists($printed, 'The command was stopped before it printed');
        self::assertSame(ProcessRunner::TIMED_OUT, $results['slow']->exitCode);
        self::assertSame('php timed out after 0.5 seconds', $results['slow']->errors);
        self::assertSame('', $results['slow']->output);
        self::assertTrue($results['quick']->succeeded());
        self::assertSame('done', $results['quick']->output);
    }

    public function testACommandKilledBySignalFailsClosedAndDiscardsPartialOutput(): void
    {
        $result = new ProcessRunner()->run(['sh', '-c', 'echo partial; kill -9 $$'], sys_get_temp_dir());

        self::assertSame(127, $result->exitCode);
        self::assertSame('', $result->output);
        self::assertSame('cannot run sh', $result->errors);
    }

    public function testEveryCommandKilledBySignalWhileOthersRunFailsClosed(): void
    {
        $commands = [];

        foreach (range(0, 9) as $index) {
            $commands['killed'.$index] = [['sh', '-c', sprintf('echo partial; sleep 0.0%d; kill -9 $$', $index)], []];
        }

        $commands['healthy'] = [['sh', '-c', 'sleep 0.2; echo fine'], []];

        $results = new ProcessRunner()->runConcurrently($commands, sys_get_temp_dir());

        foreach (range(0, 9) as $index) {
            self::assertSame(127, $results['killed'.$index]->exitCode);
            self::assertSame('', $results['killed'.$index]->output);
            self::assertSame('cannot run sh', $results['killed'.$index]->errors);
        }

        self::assertSame("fine\n", $results['healthy']->output);
    }

    public function testACommandInAMissingDirectoryFailsClosedWithoutOutput(): void
    {
        $result = new ProcessRunner()->run(['php', '-r', 'echo "ran";'], '/nonexistent/intakt-directory');

        self::assertSame(127, $result->exitCode);
        self::assertSame('', $result->output);
        self::assertSame('cannot run php', $result->errors);
    }

    public function testACommandInheritsTheEnvironmentTheRunnerWasGiven(): void
    {
        putenv('INTAKT_RUNNER_PROBE=inherited');

        try {
            $result = new ProcessRunner()->run(['php', '-r', 'echo getenv("INTAKT_RUNNER_PROBE");'], sys_get_temp_dir());
        } finally {
            putenv('INTAKT_RUNNER_PROBE');
        }

        self::assertSame('inherited', $result->output);
    }

    public function testANonPositiveTimeLimitTimesOutWithoutRunningTheCommand(): void
    {
        $marker = sys_get_temp_dir().'/intakt-runner-'.bin2hex(random_bytes(6));

        $result = new ProcessRunner()->runWithin(['php', '-r', 'touch($argv[1]);', $marker], sys_get_temp_dir(), 0);

        self::assertSame(ProcessRunner::TIMED_OUT, $result->exitCode);
        self::assertSame('php timed out after 0 seconds', $result->errors);
        self::assertFileDoesNotExist($marker);
    }

    public function testATimedCommandInAMissingDirectoryFailsClosedWithoutOutput(): void
    {
        $result = new ProcessRunner('/nonexistent/unshare')->runWithin(['php', '-r', 'echo "ran";'], '/nonexistent/intakt-directory', 10);

        self::assertSame(127, $result->exitCode);
        self::assertSame('', $result->output);
        self::assertSame('cannot run php', $result->errors);
    }

    public function testADescendantStillHoldingThePipeAfterTheParentExitedIsKilledAtTheDeadline(): void
    {
        $marker = $this->markerFile();
        $runner = new ProcessRunner('/nonexistent/unshare');

        $result = $runner->runWithin(['sh', '-c', 'sleep 30 & echo $! > '.escapeshellarg($marker).'; exit 0'], sys_get_temp_dir(), 0.3);

        self::assertSame(ProcessRunner::TIMED_OUT, $result->exitCode);
        self::assertSame(ProcessRunner::PROCESS_GROUP, $runner->containment());
        $this->assertGoneSoon($this->pidIn($marker));
    }

    public function testADescendantInTheProcessGroupIsKilledWhenTheParentTimesOut(): void
    {
        $marker = $this->markerFile();

        $result = new ProcessRunner('/nonexistent/unshare')->runWithin(['sh', '-c', 'sleep 30 & echo $! > '.escapeshellarg($marker).'; wait'], sys_get_temp_dir(), 0.3);

        self::assertSame(ProcessRunner::TIMED_OUT, $result->exitCode);
        $this->assertGoneSoon($this->pidIn($marker));
    }

    public function testADetachedDescendantWithACleanEnvironmentIsKilledInNamespaceMode(): void
    {
        $runner = new ProcessRunner();
        $this->skipWhenNamespacesAreRefused($runner);
        $marker = $this->markerFile();
        $duration = $this->uniqueDuration();

        $result = $runner->runWithin(['sh', '-c', 'env -i setsid sh -c '.escapeshellarg(': > '.escapeshellarg($marker).'; exec sleep '.$duration).' & wait'], sys_get_temp_dir(), 0.5);

        self::assertSame(ProcessRunner::TIMED_OUT, $result->exitCode);
        self::assertSame(ProcessRunner::PID_NAMESPACE, $runner->containment());
        $this->assertStartedAndGone($marker, $duration);
    }

    public function testADetachedDescendantIsKilledInNamespaceModeEvenWhenTheCommandEndsFirst(): void
    {
        $runner = new ProcessRunner();
        $this->skipWhenNamespacesAreRefused($runner);
        $marker = $this->markerFile();
        $duration = $this->uniqueDuration();

        $result = $runner->runWithin(['sh', '-c', 'env -i setsid sh -c '.escapeshellarg(': > '.escapeshellarg($marker).'; exec sleep '.$duration).' >/dev/null 2>&1 & sleep 1; exit 0'], sys_get_temp_dir(), 10);

        self::assertTrue($result->succeeded());
        $this->assertStartedAndGone($marker, $duration);
    }

    public function testWithoutUnshareTheProcessGroupIsUsedAndReported(): void
    {
        $runner = new ProcessRunner('/nonexistent/unshare');

        $result = $runner->runWithin(['php', '-r', 'echo "ran"; exit(4);'], sys_get_temp_dir(), 10);

        self::assertSame(ProcessRunner::PROCESS_GROUP, $runner->containment());
        self::assertSame(4, $result->exitCode);
        self::assertSame('ran', $result->output);
    }

    public function testNamespaceModeKeepsOutputAndExitCode(): void
    {
        $runner = new ProcessRunner();
        $this->skipWhenNamespacesAreRefused($runner);

        $result = $runner->runWithin(['php', '-r', 'echo "out"; fwrite(STDERR, "err"); exit(4);'], sys_get_temp_dir(), 10);

        self::assertSame(4, $result->exitCode);
        self::assertSame('out', $result->output);
        self::assertSame('err', $result->errors);
    }

    protected function tearDown(): void
    {
        foreach ($this->markers as $marker) {
            if (is_file($marker)) {
                unlink($marker);
            }
        }
    }

    private function skipWhenNamespacesAreRefused(ProcessRunner $runner): void
    {
        if (ProcessRunner::PID_NAMESPACE !== $runner->containment()) {
            self::markTestSkipped('This host refuses unprivileged user namespaces.');
        }
    }

    private function markerFile(): string
    {
        $marker = sys_get_temp_dir().'/intakt-runner-'.bin2hex(random_bytes(6)).'.pid';
        $this->markers[] = $marker;

        return $marker;
    }

    private function pidIn(string $marker): int
    {
        return (int) trim((string) file_get_contents($marker));
    }

    private function uniqueDuration(): string
    {
        return '30.'.random_int(100000, 999999);
    }

    private function assertStartedAndGone(string $marker, string $duration): void
    {
        self::assertFileExists($marker, 'The detached child never started');

        for ($attempt = 0; $attempt < 50 && $this->isSleeping($duration); ++$attempt) {
            usleep(100000);
        }

        self::assertFalse($this->isSleeping($duration), 'A sleep of '.$duration.' seconds survived');
    }

    private function isSleeping(string $duration): bool
    {
        $commandLines = glob('/proc/[0-9]*/cmdline');

        foreach (false === $commandLines ? [] : $commandLines as $file) {
            $arguments = explode("\0", rtrim((string) @file_get_contents($file), "\0"));

            if (['sleep', $duration] === $arguments) {
                return true;
            }
        }

        return false;
    }

    private function assertGoneSoon(int $pid): void
    {
        self::assertGreaterThan(0, $pid, 'The child did not record its process id');

        for ($attempt = 0; $attempt < 50 && $this->isRunning($pid); ++$attempt) {
            usleep(100000);
        }

        self::assertFalse($this->isRunning($pid), 'Process '.$pid.' survived');
    }

    private function isRunning(int $pid): bool
    {
        $status = (string) @file_get_contents('/proc/'.$pid.'/stat');
        $end = strrpos($status, ')');

        return false !== $end && 'Z' !== substr($status, $end + 2, 1);
    }
}
