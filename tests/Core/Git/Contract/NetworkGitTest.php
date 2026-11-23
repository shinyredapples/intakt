<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\Contract;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\GitCommand;
use Intakt\Core\Git\Contract\GitEnvironment;
use Intakt\Core\Git\Contract\NetworkGit;
use Intakt\Core\Git\Contract\NetworkGitFailed;
use Intakt\Tests\Core\Git\RecordingClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversClass(NetworkGit::class)]
#[CoversClass(NetworkGitFailed::class)]
#[UsesClass(GitCall::class)]
final class NetworkGitTest extends TestCase
{
    private string $directory;

    public function testACallThatSucceedsRunsOnceAndNeverSleeps(): void
    {
        $clock = new RecordingClock();
        $runs = 0;
        $this->initBare($this->directory.'/present');

        $output = new NetworkGit($clock, new NullLogger())->runEachAttempt(function () use (&$runs): GitCall {
            ++$runs;

            return $this->listRemote($this->directory.'/present');
        });

        self::assertSame('', $output);
        self::assertSame(1, $runs);
        self::assertSame([], $clock->sleeps);
    }

    public function testAFailingCallIsRetriedAfterTenAndThenThirtySeconds(): void
    {
        $clock = new RecordingClock();

        try {
            new NetworkGit($clock, new NullLogger())->run($this->listRemote($this->directory.'/missing'));
            self::fail('A call that always fails must fail.');
        } catch (NetworkGitFailed) {
            self::assertSame([10, 30], $clock->sleeps);
        }
    }

    public function testEachWaitIsLoggedAsAWarningWithTheAttemptTheDelayAndGitsMessage(): void
    {
        $log = new BufferingLogger();
        $missing = $this->listRemote($this->directory.'/missing');
        $gitsMessage = $this->errorOutputOf($missing);

        try {
            new NetworkGit(new RecordingClock(), $log)->run($missing);
            self::fail('A call that always fails must fail.');
        } catch (NetworkGitFailed) {
            self::assertSame([
                ['warning', 'A read-only git call failed, retrying.', ['attempt' => 1, 'delaySeconds' => 10, 'message' => $gitsMessage]],
                ['warning', 'A read-only git call failed, retrying.', ['attempt' => 2, 'delaySeconds' => 30, 'message' => $gitsMessage]],
            ], $log->cleanLogs());
        }
    }

    public function testACallThatFailsThreeTimesFailsWithGitsMessage(): void
    {
        $this->expectException(NetworkGitFailed::class);
        $this->expectExceptionMessageMatches('/^git failed after 3 attempts: .*missing/s');

        new NetworkGit(new RecordingClock(), new NullLogger())->run($this->listRemote($this->directory.'/missing'));
    }

    public function testACallThatFailsOnceSucceedsOnTheRetry(): void
    {
        $remote = $this->directory.'/late';
        $clock = new RecordingClock(function (int $sleeps) use ($remote): void {
            $this->initBare($remote);
        });

        $output = new NetworkGit($clock, new NullLogger())->run($this->listRemote($remote));

        self::assertSame('', $output);
        self::assertSame([10], $clock->sleeps);
    }

    public function testACallKilledByASignalIsRetriedAndThenFailsWithItsReason(): void
    {
        $clock = new RecordingClock();
        $killed = new GitCall(new GitCommand(PHP_BINARY, '-r', 'posix_kill(getmypid(), SIGTERM);'), $this->directory, GitEnvironment::of());

        try {
            new NetworkGit($clock, new NullLogger())->run($killed);
            self::fail('A call that is always killed must fail.');
        } catch (NetworkGitFailed $failed) {
            self::assertSame([10, 30], $clock->sleeps);
            self::assertStringContainsString('signal', $failed->getMessage());
        }
    }

    public function testACallThatCannotStartIsRetriedAndThenFails(): void
    {
        $clock = new RecordingClock();

        try {
            new NetworkGit($clock, new NullLogger())->run(new GitCall(new GitCommand('git', 'version'), $this->directory.'/missing', GitEnvironment::of()));
            self::fail('A call that cannot start must fail.');
        } catch (NetworkGitFailed $failed) {
            self::assertSame([10, 30], $clock->sleeps);
            self::assertStringContainsString('could not start', $failed->getMessage());
        }
    }

    public function testACallThatTimesOutOnEveryAttemptNamesTheLimitButNeverTheCredentialInItsRemote(): void
    {
        $clock = new RecordingClock();
        $log = new BufferingLogger();
        $credential = 'not-a-real-'.bin2hex(random_bytes(4));
        $hanging = new GitCall(new GitCommand(PHP_BINARY, '-r', 'sleep(30);', 'https://intakt:'.$credential.'@localhost/repo.git'), $this->directory, GitEnvironment::of());

        try {
            new NetworkGit($clock, $log, timeoutSeconds: 0.2)->run($hanging);
            self::fail('A call that always times out must fail.');
        } catch (NetworkGitFailed $failed) {
            $warnings = json_encode($log->cleanLogs(), JSON_THROW_ON_ERROR);

            self::assertSame([10, 30], $clock->sleeps);
            self::assertStringContainsString('timed out after 0.2 seconds', $failed->getMessage());
            self::assertStringNotContainsString($credential, $failed->getMessage());
            self::assertStringContainsString('timed out', $warnings);
            self::assertStringNotContainsString($credential, $warnings);
        }
    }

    public function testEachAttemptRunsTheCallBuiltForIt(): void
    {
        $remote = $this->directory.'/late';
        $attempts = 0;
        $clock = new RecordingClock(function (int $sleeps) use ($remote): void {
            $this->initBare($remote);
        });

        $output = new NetworkGit($clock, new NullLogger())->runEachAttempt(function () use ($remote, &$attempts): GitCall {
            ++$attempts;

            return $this->listRemote(1 === $attempts ? $remote.'-never' : $remote);
        });

        self::assertSame('', $output);
        self::assertSame(2, $attempts);
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-network-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    private function errorOutputOf(GitCall $call): string
    {
        $process = new Process([...$call->command], $call->directory, [...$call->environment]);
        $process->run();

        return trim($process->getErrorOutput());
    }

    private function initBare(string $path): void
    {
        $process = new Process(['git', 'init', '--quiet', '--bare', $path]);
        $process->mustRun();
    }

    private function listRemote(string $remote): GitCall
    {
        return new GitCall(new GitCommand('git', 'ls-remote', '--', $remote), $this->directory, GitEnvironment::of(GIT_CONFIG_GLOBAL: '/dev/null'));
    }
}
