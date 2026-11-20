<?php

declare(strict_types=1);

namespace Intakt\Tests\Application\Parity;

use Intakt\Tests\Application\Parity\Fixtures\AbstractCandidate;
use Intakt\Tests\Application\Parity\Fixtures\PlainCandidate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(ParityRecord::class)]
#[CoversClass(TestClassIndex::class)]
final class ParityRecordTest extends TestCase
{
    /** @var list<string> */
    private array $scratches = [];

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->scratches);
        $this->scratches = [];
    }

    public function testEveryDoneParityItemNamesAnIntaktTestThatExists(): void
    {
        $root = dirname(__DIR__, 3);
        $record = (string) file_get_contents($root.'/docs/architecture/parity.md');

        self::assertSame([], $this->repositoryTests()->unprovenItems($record));
    }

    public function testADoneItemNamingAnExistingTestMethodPasses(): void
    {
        $record = '- [x] A behavior. `test_old.py::test_it` [T1]; Intakt: `ParityRecordTest::testADoneItemNamingAnExistingTestMethodPasses`';

        self::assertSame([], $this->repositoryTests()->unprovenItems($record));
    }

    public function testADoneItemWhoseTestMethodDoesNotExistIsReportedWithItsLine(): void
    {
        $record = "## Section\n- [x] A behavior. `test_old.py` [T1]; Intakt: `ParityRecordTest::testNothingLikeThis`";

        self::assertSame(
            ['line 2: ParityRecordTest::testNothingLikeThis is no test method of '.self::class],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testADoneItemWhoseTestClassDoesNotExistIsReported(): void
    {
        $record = '- [x] A behavior. `test_old.py` [T1]; Intakt: `NoSuchParityTest::testAnything`';

        self::assertSame(
            ['line 1: NoSuchParityTest is no test class under tests/'],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testADoneItemNamingOnlyTheExistingLoopsTestIsReported(): void
    {
        $record = '- [x] A behavior. `test_old.py::test_it_works` [T1]';

        self::assertSame(
            ['line 1: names no Intakt test after `Intakt:`'],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testABareMethodAfterAClassIsCheckedOnThatClass(): void
    {
        $record = '- [x] A behavior. [T1]; Intakt: `ParityRecordTest::testADoneItemNamingAnExistingTestMethodPasses` and `testNothingLikeThis`';

        self::assertSame(
            ['line 1: ParityRecordTest::testNothingLikeThis is no test method of '.self::class],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testABareMethodBeforeAnyClassIsReported(): void
    {
        $record = '- [x] A behavior. [T1]; Intakt: `testADoneItemNamingAnExistingTestMethodPasses`';

        self::assertSame(
            ['line 1: `testADoneItemNamingAnExistingTestMethodPasses` follows no test class'],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testAnUncheckedItemNamingAMissingTestIsNotJudged(): void
    {
        $record = '- [ ] A behavior. `test_old.py` [T1]; Intakt: `NoSuchParityTest::testAnything`';

        self::assertSame([], $this->repositoryTests()->unprovenItems($record));
    }

    public function testAnItemCheckedWithACapitalXIsJudged(): void
    {
        $record = '- [X] A behavior. `test_old.py` [T1]';

        self::assertSame(
            ['line 1: names no Intakt test after `Intakt:`'],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testABacktickedCommandOrClassInARemarkIsNotReadAsATestName(): void
    {
        $record = '- [x] A behavior. [T1]; Intakt: `ParityRecordTest::testADoneItemNamingAnExistingTestMethodPasses` (`intakt:build:run` runs the `FencedPlants` gate)';

        self::assertSame([], $this->repositoryTests()->unprovenItems($record));
    }

    public function testAClassNameThatTwoTestFilesShareIsReported(): void
    {
        $tests = $this->scratchTests(['A/TwinTest.php', 'B/TwinTest.php']);

        self::assertSame(
            ['line 1: TwinTest names more than one test class: Intakt\Tests\Scratch\A\TwinTest, Intakt\Tests\Scratch\B\TwinTest'],
            $tests->unprovenItems('- [x] A behavior. [T1]; Intakt: `TwinTest::testIt`'),
        );
    }

    public function testTheTwoClassesOfASharedNameAreReportedInTheSameOrderWhateverTheFolderOrder(): void
    {
        $first = $this->scratchTests(['A/TwinTest.php', 'B/TwinTest.php'])->unprovenItems('- [x] Item. Intakt: `TwinTest::testIt`');
        $second = $this->scratchTests(['B/TwinTest.php', 'A/TwinTest.php'])->unprovenItems('- [x] Item. Intakt: `TwinTest::testIt`');

        self::assertSame($first, $second);
    }

    public function testAMisspelledClassAfterAValidNameIsReported(): void
    {
        $record = '- [x] Item. Intakt: `ParityRecordTest::testADoneItemNamingAnExistingTestMethodPasses` and `ParityRecordTes::testNothing`';

        self::assertSame(
            ['line 1: `ParityRecordTes::testNothing` is not a test name of the form `SomeTest::testName`'],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testAMisspelledMethodPrefixAfterAValidNameIsReported(): void
    {
        $record = '- [x] Item. Intakt: `ParityRecordTest::testADoneItemNamingAnExistingTestMethodPasses` and `ParityRecordTest::tesNothing`';

        self::assertSame(
            ['line 1: `ParityRecordTest::tesNothing` is not a test name of the form `SomeTest::testName`'],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testAMethodNameWithParenthesesAfterAValidNameIsReported(): void
    {
        $record = '- [x] Item. Intakt: `ParityRecordTest::testADoneItemNamingAnExistingTestMethodPasses` and `ParityRecordTest::testNothing()`';

        self::assertSame(
            ['line 1: `ParityRecordTest::testNothing()` is not a test name of the form `SomeTest::testName`'],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testANonPublicMethodIsNoTestMethod(): void
    {
        $record = '- [x] Item. Intakt: `ParityRecordTest::testAProtectedHelperIsNoTest`';

        self::assertSame(
            ['line 1: ParityRecordTest::testAProtectedHelperIsNoTest is no test method of '.self::class],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testAnAbstractTestClassIsNoRunnableTestClass(): void
    {
        $record = '- [x] Item. Intakt: `AbstractCandidateTest::testVisible`';

        self::assertSame(
            ['line 1: AbstractCandidateTest is no runnable test class'],
            $this->scratchTestsAliasing(AbstractCandidate::class, 'AbstractCandidateTest')->unprovenItems($record),
        );
    }

    public function testAClassThatIsNoPhpunitTestCaseIsNoRunnableTestClass(): void
    {
        $record = '- [x] Item. Intakt: `PlainCandidateTest::testVisible`';

        self::assertSame(
            ['line 1: PlainCandidateTest is no runnable test class'],
            $this->scratchTestsAliasing(PlainCandidate::class, 'PlainCandidateTest')->unprovenItems($record),
        );
    }

    public function testAMissingNameInADoubleBacktickSpanAfterAValidNameIsReported(): void
    {
        $record = '- [x] Item. Intakt: `ParityRecordTest::testAnIndentedDoneItemIsJudged` and ``ParityRecordTest::testNothing``';

        self::assertSame(
            ['line 1: ParityRecordTest::testNothing is no test method of '.self::class],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testAMissingNameAfterAnEscapedBacktickOnADoneItemIsReported(): void
    {
        $record = '- [x] Item. Intakt: `ParityRecordTest::testAnIndentedDoneItemIsJudged` (literal \` example) and `ParityRecordTest::testNothing`';

        self::assertSame(
            ['line 1: ParityRecordTest::testNothing is no test method of '.self::class],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testAnEscapedBacktickBesideValidNamesIsNotReported(): void
    {
        $record = '- [x] Item. Intakt: `ParityRecordTest::testAnIndentedDoneItemIsJudged` (literal \` example) and `testABareMethodAfterAClassIsCheckedOnThatClass`';

        self::assertSame([], $this->repositoryTests()->unprovenItems($record));
    }

    public function testANameWithASingleColonAfterAValidNameIsReportedAsMalformed(): void
    {
        $record = '- [x] Item. Intakt: `ParityRecordTest::testAnIndentedDoneItemIsJudged` and `ParityRecordTest:testNothing`';

        self::assertSame(
            ['line 1: `ParityRecordTest:testNothing` is not a test name of the form `SomeTest::testName`'],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testANameWithAnArrowAfterAValidNameIsReportedAsMalformed(): void
    {
        $record = '- [x] Item. Intakt: `ParityRecordTest::testAnIndentedDoneItemIsJudged` and `ParityRecordTest->testNothing`';

        self::assertSame(
            ['line 1: `ParityRecordTest->testNothing` is not a test name of the form `SomeTest::testName`'],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testANameWithADotAfterAValidNameIsReportedAsMalformed(): void
    {
        $record = '- [x] Item. Intakt: `ParityRecordTest::testAnIndentedDoneItemIsJudged` and `ParityRecordTest.testNothing`';

        self::assertSame(
            ['line 1: `ParityRecordTest.testNothing` is not a test name of the form `SomeTest::testName`'],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testAWordThatOnlyContainsTestIsNoNearMiss(): void
    {
        $record = '- [x] Item. Intakt: `ParityRecordTest::testAnIndentedDoneItemIsJudged` (see `attest.Contest`, `Testing::run` and `latest`)';

        self::assertSame([], $this->repositoryTests()->unprovenItems($record));
    }

    public function testADoneItemWithAnUnclosedBacktickAfterIntaktIsReported(): void
    {
        $record = '- [x] Item. Intakt: `ParityRecordTest::testAnIndentedDoneItemIsJudged` and `ParityRecordTest::testNothing';

        self::assertSame(
            ['line 1: has a backtick after `Intakt:` that is never closed'],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testANonBlankLineDirectlyAfterADoneItemIsReportedAsADoneItemOverMoreThanOneLine(): void
    {
        $record = "- [x] Item. Intakt: `ParityRecordTest::testAnIndentedDoneItemIsJudged`\n  and `ParityRecordTest::testNothing`";

        self::assertSame(
            ['line 2: a done item must stay on one line'],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testAListItemOrHeadingOrBlankLineDirectlyAfterADoneItemIsNoContinuation(): void
    {
        $done = '- [x] Item. Intakt: `ParityRecordTest::testAnIndentedDoneItemIsJudged`';
        $record = implode("\n", [$done, '- [ ] Next item.', $done, '## Next section', $done, '', 'A paragraph.', $done, '  * A nested item.', $done, '1. A numbered item.']);

        self::assertSame([], $this->repositoryTests()->unprovenItems($record));
    }

    public function testANonBlankLineAfterAnUncheckedItemIsNotJudged(): void
    {
        $record = "- [ ] Item. Intakt: `NoSuchParityTest::testAnything`\n  and more words";

        self::assertSame([], $this->repositoryTests()->unprovenItems($record));
    }

    public function testAnIndentedDoneItemIsJudged(): void
    {
        $record = '  - [x] A behavior. `test_old.py` [T1]';

        self::assertSame(
            ['line 1: names no Intakt test after `Intakt:`'],
            $this->repositoryTests()->unprovenItems($record),
        );
    }

    public function testATestFileUnderAFixturesFolderIsNoTestClass(): void
    {
        $tests = $this->scratchTests(['Fixtures/OnlyFixtureTest.php', 'A/TwinTest.php', 'Fixtures/TwinTest.php']);

        self::assertSame(
            ['line 1: OnlyFixtureTest is no test class under tests/', 'line 1: TwinTest is no test class under tests/'],
            $tests->unprovenItems('- [x] A behavior. [T1]; Intakt: `OnlyFixtureTest::testIt`, `TwinTest::testIt`'),
        );
    }

    protected function testAProtectedHelperIsNoTest(): void
    {
        $this->addToAssertionCount(1);
    }

    private function repositoryTests(): ParityRecord
    {
        return new ParityRecord(dirname(__DIR__, 2), 'Intakt\Tests');
    }

    /**
     * @param list<string> $files
     */
    private function scratchTests(array $files): ParityRecord
    {
        $scratch = sys_get_temp_dir().'/intakt-parity-record-'.bin2hex(random_bytes(6));
        $this->scratches[] = $scratch;
        foreach ($files as $file) {
            $path = $scratch.'/'.$file;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0o700, true);
            }
            touch($path);
        }

        return new ParityRecord($scratch, 'Intakt\Tests\Scratch');
    }

    // A class alias lives for the whole process, so each call takes its own folder and alias, and a repeated run raises no warning.
    private function scratchTestsAliasing(string $class, string $testName): ParityRecord
    {
        $folder = 'Run'.bin2hex(random_bytes(6));
        class_alias($class, 'Intakt\Tests\Scratch\\'.$folder.'\\'.$testName);

        return $this->scratchTests([$folder.'/'.$testName.'.php']);
    }
}
