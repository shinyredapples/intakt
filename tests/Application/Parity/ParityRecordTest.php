<?php

declare(strict_types=1);

namespace Intakt\Tests\Application\Parity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParityRecord::class)]
final class ParityRecordTest extends TestCase
{
    private ?string $scratch = null;

    protected function tearDown(): void
    {
        if (null !== $this->scratch) {
            exec('rm -rf '.escapeshellarg($this->scratch));
        }
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

    public function testATestFileUnderAFixturesFolderIsNoTestClass(): void
    {
        $tests = $this->scratchTests(['Fixtures/OnlyFixtureTest.php', 'A/TwinTest.php', 'Fixtures/TwinTest.php']);

        self::assertSame(
            ['line 1: OnlyFixtureTest is no test class under tests/', 'line 1: TwinTest is no test class under tests/'],
            $tests->unprovenItems('- [x] A behavior. [T1]; Intakt: `OnlyFixtureTest::testIt`, `TwinTest::testIt`'),
        );
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
        $this->scratch = sys_get_temp_dir().'/intakt-parity-record-'.bin2hex(random_bytes(6));
        foreach ($files as $file) {
            $path = $this->scratch.'/'.$file;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0o700, true);
            }
            touch($path);
        }

        return new ParityRecord($this->scratch, 'Intakt\Tests\Scratch');
    }
}
