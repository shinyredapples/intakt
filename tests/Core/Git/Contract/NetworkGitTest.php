<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\Contract;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\NetworkGit;
use Intakt\Core\Git\Contract\NetworkGitFailed;
use Intakt\Tests\Core\Git\RecordingClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversClass(NetworkGit::class)]
#[CoversClass(NetworkGitFailed::class)]
#[UsesClass(GitCall::class)]
final class NetworkGitTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-network-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testACallThatSucceedsRunsOnceAndNeverSleeps(): void
    {
        $clock = new RecordingClock();
        $this->initBare($this->directory.'/present');

        $output = new NetworkGit($clock, new NullLogger())->run($this->listRemote($this->directory.'/present'));

        self::assertSame('', $output);
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

    private function listRemote(string $remote): GitCall
    {
        return new GitCall(['git', 'ls-remote', '--', $remote], $this->directory, ['GIT_CONFIG_GLOBAL' => '/dev/null']);
    }

    private function initBare(string $path): void
    {
        $process = new Process(['git', 'init', '--quiet', '--bare', $path]);
        $process->mustRun();
    }
}
