<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Build\UseCase\RunBuildJob\BuildLogReading;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BuildLogReading::class)]
final class BuildLogReadingTest extends TestCase
{
    public function testTheSameMessageSeenTwiceCountsItsTokensOnce(): void
    {
        $log = BuildLogLines::usage('m1', 100).BuildLogLines::usage('m1', 100).BuildLogLines::usage('m2', 50);

        self::assertSame(150, BuildLogReading::of($log)->tokens);
    }

    public function testCacheTokensCountApartFromTheInput(): void
    {
        $log = json_encode(['type' => 'assistant', 'message' => ['id' => 'm', 'usage' => ['input_tokens' => 1, 'output_tokens' => 2, 'cache_creation_input_tokens' => 3, 'cache_read_input_tokens' => 4]]])."\n";

        self::assertSame(10, BuildLogReading::of($log)->tokens);
    }

    public function testALineThatIsNoJsonAndAUsageWithTextCountsAreSkipped(): void
    {
        $log = "not json\n{broken\n".json_encode(['type' => 'result', 'usage' => ['input_tokens' => 'many', 'output_tokens' => -5]])."\n[1]\n";

        self::assertSame(0, BuildLogReading::of($log)->tokens);
    }

    public function testAnEditThatOnlyChangesWhitespaceLeavesTheFingerprintAsItWas(): void
    {
        $none = BuildLogReading::of('')->contentFingerprint;

        self::assertSame($none, BuildLogReading::of(BuildLogLines::edit('c1', "a  b\n", 'a b'))->contentFingerprint);
    }

    public function testAnEditThatChangesContentChangesTheFingerprint(): void
    {
        self::assertNotSame(BuildLogReading::of('')->contentFingerprint, BuildLogReading::of(BuildLogLines::edit('c1', 'a', 'b'))->contentFingerprint);
    }

    public function testAnEmptyCommitIsNoContentChangeAndARealCommitIs(): void
    {
        $none = BuildLogReading::of('')->contentFingerprint;

        self::assertSame($none, BuildLogReading::of(BuildLogLines::command('c1', 'git commit --allow-empty -m x'))->contentFingerprint);
        self::assertNotSame($none, BuildLogReading::of(BuildLogLines::command('c1', 'git commit -m x'))->contentFingerprint);
    }

    public function testAFailingTestRunNamesItsFailuresByTestName(): void
    {
        $log = BuildLogLines::command('c1', 'vendor/bin/phpunit').BuildLogLines::result('c1', "There were 2 failures:\n\n1) App\\FooTest::testOne\nboom\n\n2) App\\FooTest::testTwo\n");

        self::assertSame(['App\FooTest::testOne', 'App\FooTest::testTwo'], array_keys(BuildLogReading::of($log)->failingTests));
    }

    public function testALaterPassingRunClearsTheFailures(): void
    {
        $log = BuildLogLines::command('c1', 'vendor/bin/phpunit').BuildLogLines::result('c1', "1) App\\FooTest::testOne\n")
            .BuildLogLines::command('c2', 'vendor/bin/phpunit').BuildLogLines::result('c2', "OK (3 tests)\n");

        self::assertSame([], BuildLogReading::of($log)->failingTests);
    }

    public function testACheckIsRunningUntilItsResultIsSeen(): void
    {
        $running = BuildLogLines::command('c1', 'bin/check --fast');

        self::assertTrue(BuildLogReading::of($running)->checkRunning);
        self::assertFalse(BuildLogReading::of($running.BuildLogLines::result('c1', 'done'))->checkRunning);
    }

    public function testACommandThatIsNoCheckIsNeverARunningCheck(): void
    {
        self::assertFalse(BuildLogReading::of(BuildLogLines::command('c1', 'ls -la'))->checkRunning);
    }
}
