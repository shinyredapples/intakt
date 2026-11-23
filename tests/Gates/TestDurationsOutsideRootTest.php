<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\TestDurations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(TestDurations::class)]
final class TestDurationsOutsideRootTest extends TestCase
{
    private string $directory;

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
        $this->directory = sys_get_temp_dir().'/intakt-durations-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    private function reportNaming(string $file): string
    {
        $cases = sprintf('<testcase name="t" file="/work/tests/InsideTest.php" time="0.5"/><testcase name="t" file="%s" time="2.0"/>', $file);
        file_put_contents($this->directory.'/report.xml', '<?xml version="1.0"?><testsuites><testsuite name="s">'.$cases.'</testsuite></testsuites>');

        return $this->directory.'/report.xml';
    }
}
