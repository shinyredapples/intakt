<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\TestDurations;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(TestDurations::class)]
final class TestDurationsTest extends SandboxTestCase
{
    private string $directory;

    public function testTimesOfTestCasesInOneFileAreSummedUnderThatFile(): void
    {
        $report = $this->report('a.xml', ['/work/tests/AlphaTest.php' => [0.25, 0.5], '/work/tests/BetaTest.php' => [0.1]]);

        $durations = new TestDurations('/work')->read([$report]);

        self::assertSame(['tests/AlphaTest.php', 'tests/BetaTest.php'], array_keys($durations));
        self::assertEqualsWithDelta(0.75, $durations['tests/AlphaTest.php'], 0.0001);
    }

    public function testBothPassesAreMergedIntoOneEntryPerFileSlowestFirst(): void
    {
        $first = $this->report('a.xml', ['/work/tests/AlphaTest.php' => [0.5], '/work/tests/SlowTest.php' => [2.0]]);
        $second = $this->report('b.xml', ['/work/tests/Gates/GateTest.php' => [1.0], '/work/tests/AlphaTest.php' => [0.25]]);

        $durations = new TestDurations('/work')->read([$first, $second]);

        self::assertSame(['tests/SlowTest.php', 'tests/Gates/GateTest.php', 'tests/AlphaTest.php'], array_keys($durations));
        self::assertEqualsWithDelta(0.75, $durations['tests/AlphaTest.php'], 0.0001);
    }

    public function testOnlyTheTenSlowestFilesAreShownSlowestFirst(): void
    {
        $durations = [];

        foreach (range(1, 12) as $number) {
            $durations['tests/File'.$number.'Test.php'] = (float) (13 - $number);
        }

        $slowest = new TestDurations('/work')->slowest($durations);

        self::assertCount(10, $slowest);
        self::assertSame('tests/File1Test.php', array_key_first($slowest));
        self::assertArrayNotHasKey('tests/File11Test.php', $slowest);
    }

    public function testAMissingOrBrokenReportYieldsNoDurations(): void
    {
        file_put_contents($this->directory.'/broken.xml', '<testsuites><testcase');

        $durations = new TestDurations('/work')->read([$this->directory.'/missing.xml', $this->directory.'/broken.xml']);

        self::assertSame([], $durations);
    }

    public function testDurationsAreWrittenAsOneEntryPerFileInTheCache(): void
    {
        new TestDurations('/work')->write(['tests/AlphaTest.php' => 0.75], $this->directory);

        $written = json_decode((string) file_get_contents($this->directory.'/tests/durations.json'), true);

        self::assertSame(['tests/AlphaTest.php' => 0.75], $written);
    }

    public function testACacheThatIsAPlainFileIsLeftAloneWithoutAWarning(): void
    {
        file_put_contents($this->directory.'/plain', 'x');

        $warnings = $this->warningsOf(fn () => new TestDurations('/work')->write(['tests/AlphaTest.php' => 0.75], $this->directory.'/plain'));

        self::assertSame([], $warnings);
        self::assertSame('x', file_get_contents($this->directory.'/plain'));
    }

    public function testADurationsPathThatIsADirectoryIsLeftAloneWithoutAWarning(): void
    {
        mkdir($this->directory.'/tests/durations.json', 0o777, true);

        $warnings = $this->warningsOf(fn () => new TestDurations('/work')->write(['tests/AlphaTest.php' => 0.75], $this->directory));

        self::assertSame([], $warnings);
        self::assertDirectoryExists($this->directory.'/tests/durations.json');
    }

    public function testACacheDirectoryWithoutSearchPermissionIsSkippedWithoutAWarning(): void
    {
        mkdir($this->directory.'/tests', 0o200);

        try {
            $warnings = $this->warningsOf(fn () => new TestDurations('/work')->write(['tests/AlphaTest.php' => 0.75], $this->directory));
        } finally {
            chmod($this->directory.'/tests', 0o700);
        }

        self::assertSame([], $warnings);
        self::assertFileDoesNotExist($this->directory.'/tests/durations.json');
    }

    public function testTheQueueStartsWithTheLongestRecordedFile(): void
    {
        $durations = new TestDurations($this->emptySandbox()->root);

        $queue = $durations->longestFirst(['tests/ShortTest.php', 'tests/LongTest.php', 'tests/MiddleTest.php'], ['tests/ShortTest.php' => 0.5, 'tests/LongTest.php' => 12.0, 'tests/MiddleTest.php' => 3.0]);

        self::assertSame(['tests/LongTest.php', 'tests/MiddleTest.php', 'tests/ShortTest.php'], $queue);
    }

    public function testFilesWithoutARecordedDurationGoFirstTheLargestOfThemFirst(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/SmallNewTest.php', '<?php');
        $sandbox->write('tests/LargeNewTest.php', str_repeat('<?php ', 100));

        $queue = new TestDurations($sandbox->root)->longestFirst(['tests/SmallNewTest.php', 'tests/KnownTest.php', 'tests/LargeNewTest.php'], ['tests/KnownTest.php' => 60.0]);

        self::assertSame(['tests/LargeNewTest.php', 'tests/SmallNewTest.php', 'tests/KnownTest.php'], $queue);
    }

    public function testWithoutARecordEveryFileCountsAsUnrecorded(): void
    {
        $cache = $this->emptySandbox()->root;

        self::assertSame([], new TestDurations($cache)->recorded($cache));
    }

    public function testARecordThatIsNoMapOfSecondsIsIgnored(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/durations.json', '{"tests/LongTest.php": "slow", "tests/ShortTest.php": 1, "nested": {"a": 1}}');

        self::assertSame([], new TestDurations($sandbox->root)->recorded($sandbox->root));
    }

    public function testAnEntryThatIsNoNumberOfSecondsIsLeftOut(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/durations.json', '{"tests/LongTest.php": "slow", "tests/ShortTest.php": 1}');

        self::assertSame(['tests/ShortTest.php' => 1.0], new TestDurations($sandbox->root)->recorded($sandbox->root));
    }

    public function testARecordThatCannotBeReadIsIgnoredWithoutAWarning(): void
    {
        $this->skipWhenPermissionsAreIgnored();
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/durations.json', '{"tests/LongTest.php": 12.5}');
        chmod($sandbox->root.'/tests/durations.json', 0o000);

        try {
            $recorded = [];
            $warnings = $this->warningsOf(function () use ($sandbox, &$recorded): void {
                $recorded = new TestDurations($sandbox->root)->recorded($sandbox->root);
            });
        } finally {
            chmod($sandbox->root.'/tests/durations.json', 0o600);
        }

        self::assertSame([], $recorded);
        self::assertSame([], $warnings);
    }

    public function testARecordThatCanBeReadIsStillUsed(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/durations.json', '{"tests/LongTest.php": 12.5}');
        chmod($sandbox->root.'/tests/durations.json', 0o400);

        self::assertSame(['tests/LongTest.php' => 12.5], new TestDurations($sandbox->root)->recorded($sandbox->root));
    }

    public function testATestFileOutsideTheRootIsLeftOutOfTheRecordedDurations(): void
    {
        $durations = new TestDurations('/work');

        $durations->write($durations->read([$this->reportNaming('/elsewhere/tests/OutsideTest.php')]), $this->directory);

        self::assertSame(['tests/InsideTest.php' => 0.5], json_decode((string) file_get_contents($this->directory.'/tests/durations.json'), true));
        self::assertStringNotContainsString('/elsewhere', (string) file_get_contents($this->directory.'/tests/durations.json'));
    }

    public function testATestFileOutsideTheRootIsLeftOffTheSlowestLine(): void
    {
        $durations = new TestDurations('/work');

        $slowest = $durations->slowest($durations->read([$this->reportNaming('/elsewhere/tests/OutsideTest.php')]));

        self::assertSame(['tests/InsideTest.php'], array_keys($slowest));
    }

    public function testAFolderBesideTheRootThatSharesItsNameAsPrefixCountsAsOutside(): void
    {
        $durations = new TestDurations('/work')->read([$this->reportNaming('/workshop/tests/BesideTest.php')]);

        self::assertSame(['tests/InsideTest.php'], array_keys($durations));
    }

    public function testAPathThatClimbsOutOfTheRootWithDotSegmentsIsLeftOutOfTheRecordedDurationsAndTheSlowestLine(): void
    {
        $durations = new TestDurations('/work');

        $seconds = $durations->read([$this->reportNaming('/work/../elsewhere/tests/OutsideTest.php')]);
        $durations->write($seconds, $this->directory);

        self::assertSame(['tests/InsideTest.php' => 0.5], json_decode((string) file_get_contents($this->directory.'/tests/durations.json'), true));
        self::assertSame(['tests/InsideTest.php'], array_keys($durations->slowest($seconds)));
    }

    public function testAPathWithDotSegmentsThatStaysInsideTheRootKeepsItsNormalizedRelativePath(): void
    {
        $durations = new TestDurations('/work')->read([$this->reportNaming('/work/tests/./Gates/../Gates/GateTest.php')]);

        self::assertSame(['tests/Gates/GateTest.php', 'tests/InsideTest.php'], array_keys($durations));
    }

    public function testATestFileInsideTheRootKeepsItsPathRelativeToTheRoot(): void
    {
        $durations = new TestDurations('/work/')->read([$this->reportNaming('/work/tests/Gates/GateTest.php')]);

        self::assertSame(['tests/Gates/GateTest.php', 'tests/InsideTest.php'], array_keys($durations));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/intakt-durations-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory($this->directory);
    }

    /**
     * @param callable(): void $write
     *
     * @return list<string>
     */
    private function warningsOf(callable $write): array
    {
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $write();
        } finally {
            restore_error_handler();
        }

        return $warnings;
    }

    private function removeDirectory(string $directory): void
    {
        foreach (scandir($directory) as $entry) {
            if (in_array($entry, ['.', '..'], true)) {
                continue;
            }

            $path = $directory.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }

    private function reportNaming(string $file): string
    {
        $cases = sprintf('<testcase name="t" file="/work/tests/InsideTest.php" time="0.5"/><testcase name="t" file="%s" time="2.0"/>', $file);
        file_put_contents($this->directory.'/report.xml', '<?xml version="1.0"?><testsuites><testsuite name="s">'.$cases.'</testsuite></testsuites>');

        return $this->directory.'/report.xml';
    }

    /**
     * @param array<string, list<float>> $times
     */
    private function report(string $name, array $times): string
    {
        $cases = '';

        foreach ($times as $file => $seconds) {
            foreach ($seconds as $second) {
                $cases .= sprintf('<testcase name="t" file="%s" time="%s"/>', $file, $second);
            }
        }

        file_put_contents($this->directory.'/'.$name, '<?xml version="1.0"?><testsuites><testsuite name="s">'.$cases.'</testsuite></testsuites>');

        return $this->directory.'/'.$name;
    }
}
