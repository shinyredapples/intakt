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

    public function testAMultiEditThatChangesContentChangesTheFingerprint(): void
    {
        $log = json_encode(['type' => 'assistant', 'message' => ['id' => 'm', 'content' => [['type' => 'tool_use', 'id' => 'c1', 'name' => 'MultiEdit', 'input' => ['edits' => [['old_string' => 'a', 'new_string' => 'b']]]]]]])."\n";

        self::assertNotSame(BuildLogReading::of('')->contentFingerprint, BuildLogReading::of($log)->contentFingerprint);
    }

    public function testAnEmptyCommitIsNoContentChangeAndARealCommitIs(): void
    {
        $none = BuildLogReading::of('')->contentFingerprint;
        $made = "[feat/x 1a2b3c4] feat: x\n 1 file changed\n";

        self::assertSame($none, BuildLogReading::of(BuildLogLines::command('c1', 'git commit --allow-empty -m x').BuildLogLines::result('c1', $made))->contentFingerprint);
        self::assertNotSame($none, BuildLogReading::of(BuildLogLines::command('c1', 'git commit -m x').BuildLogLines::result('c1', $made))->contentFingerprint);
    }

    public function testACommitAttemptThatMadeNoCommitIsNoContentChange(): void
    {
        $none = BuildLogReading::of('')->contentFingerprint;

        self::assertSame($none, BuildLogReading::of(BuildLogLines::command('c1', 'git commit -m x'))->contentFingerprint);
        self::assertSame($none, BuildLogReading::of(BuildLogLines::command('c1', 'git commit -m x').BuildLogLines::result('c1', "nothing to commit, working tree clean\n"))->contentFingerprint);
    }

    public function testTheSameCommitCommandTwiceChangesTheFingerprintOnlyByTheCommitsItMade(): void
    {
        $once = BuildLogLines::command('c1', 'git commit -am x').BuildLogLines::result('c1', "[main 1a2b3c4] x\n");
        $again = $once.BuildLogLines::command('c2', 'git commit -am x');

        self::assertSame(BuildLogReading::of($once)->contentFingerprint, BuildLogReading::of($again.BuildLogLines::result('c2', "nothing to commit\n"))->contentFingerprint);
        self::assertNotSame(BuildLogReading::of($once)->contentFingerprint, BuildLogReading::of($again.BuildLogLines::result('c2', "[main 5d6e7f8] x\n"))->contentFingerprint);
    }

    public function testALineWithTheSameMessageIdAndALowerFigureNeverLowersTheTokens(): void
    {
        self::assertSame(900, BuildLogReading::of(BuildLogLines::usage('m1', 900).BuildLogLines::usage('m1', 20))->tokens);
    }

    public function testCountsThatAddUpPastTheLargestIntegerAreHeldThere(): void
    {
        $huge = json_encode(['type' => 'assistant', 'message' => ['id' => 'm', 'usage' => ['input_tokens' => PHP_INT_MAX, 'output_tokens' => PHP_INT_MAX]]])."\n";

        self::assertSame(PHP_INT_MAX, BuildLogReading::of($huge.str_replace('"m"', '"n"', $huge))->tokens);
    }

    public function testACheckThatWentToTheBackgroundIsRunningUntilItsShellShowsItEnded(): void
    {
        $sent = BuildLogLines::command('c1', 'bin/check --fast').BuildLogLines::result('c1', "Command running in background with ID: bash_7\n");
        $looked = $sent.BuildLogLines::tool('c2', 'BashOutput', ['bash_id' => 'bash_7']);

        self::assertTrue(BuildLogReading::of($sent)->checkRunning);
        self::assertTrue(BuildLogReading::of($looked.BuildLogLines::result('c2', "<status>running</status>\n"))->checkRunning);
        self::assertFalse(BuildLogReading::of($looked.BuildLogLines::result('c2', "<status>completed</status>\n<exit_code>0</exit_code>\n"))->checkRunning);
    }

    public function testACheckStartedInTheBackgroundWithoutANamedShellIsRunningUntilTheNextTestRunEnds(): void
    {
        $sent = BuildLogLines::tool('c1', 'Bash', ['command' => 'vendor/bin/phpunit', 'run_in_background' => true]).BuildLogLines::result('c1', "started\n");

        self::assertTrue(BuildLogReading::of($sent)->checkRunning);
        self::assertFalse(BuildLogReading::of($sent.BuildLogLines::command('c2', 'vendor/bin/phpunit').BuildLogLines::result('c2', "OK\n"))->checkRunning);
    }

    public function testAFailingCheckSeenThroughItsBackgroundShellNamesItsFailures(): void
    {
        $log = BuildLogLines::command('c1', 'vendor/bin/phpunit').BuildLogLines::result('c1', "Command running in background with ID: b1\n")
            .BuildLogLines::tool('c2', 'BashOutput', ['bash_id' => 'b1']).BuildLogLines::result('c2', "<status>failed</status>\n1) App\\FooTest::testOne\n");

        self::assertSame(['App\FooTest::testOne'], array_keys(BuildLogReading::of($log)->failingTests));
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
