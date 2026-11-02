<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\TestDurations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TestDurations::class)]
final class TestDurationsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-durations-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->directory.'/{,tests/}*.{xml,json}', GLOB_BRACE) ?: []);
        is_dir($this->directory.'/tests') && rmdir($this->directory.'/tests');
        rmdir($this->directory);
    }

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
