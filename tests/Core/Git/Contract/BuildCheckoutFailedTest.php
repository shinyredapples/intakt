<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\Contract;

use Intakt\Core\Git\Contract\BuildCheckoutFailed;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

#[CoversClass(BuildCheckoutFailed::class)]
final class BuildCheckoutFailedTest extends TestCase
{
    public function testAGitCallThatTimedOutNamesItsLimitAndNeverItsCommandLine(): void
    {
        $process = new Process(['git', 'fetch', 'https://intakt:not-a-real-credential@localhost/repo.git'], timeout: 60);

        $failed = BuildCheckoutFailed::stopped(new ProcessTimedOutException($process, ProcessTimedOutException::TYPE_GENERAL));

        self::assertSame('git timed out after 60 seconds', $failed->getMessage());
        self::assertNull($failed->getPrevious());
    }

    public function testAnyOtherProcessFailureGetsFixedWordsAndNeverItsCommandLine(): void
    {
        $failed = BuildCheckoutFailed::stopped(new RuntimeException('The command "git fetch https://intakt:not-a-real-credential@localhost/repo.git" failed.'));

        self::assertSame('git could not run', $failed->getMessage());
        self::assertNull($failed->getPrevious());
    }

    public function testAnyOtherFailureKeepsItsMessageAndItsCause(): void
    {
        $cause = new InvalidArgumentException('"a/b" is not a project name');

        $failed = BuildCheckoutFailed::because($cause);

        self::assertSame('"a/b" is not a project name', $failed->getMessage());
        self::assertSame($cause, $failed->getPrevious());
    }
}
