<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Intakt\Core\Job\Infrastructure\StartCheck;
use Intakt\Core\Job\Infrastructure\StartCheckFailure;
use Intakt\Core\Job\Infrastructure\StartCheckReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(StartCheckReport::class)]
#[UsesClass(StartCheckFailure::class)]
#[UsesClass(StartCheck::class)]
final class StartCheckReportTest extends TestCase
{
    private string $output = '';

    protected function setUp(): void
    {
        $this->output = sys_get_temp_dir().'/intakt-report-'.bin2hex(random_bytes(4));
        new Filesystem()->mkdir($this->output);
    }

    protected function tearDown(): void
    {
        new Filesystem()->chmod($this->output, 0o700);
        new Filesystem()->remove($this->output);
    }

    public function testAReportWithOnlyItsEndLineHasNoFailure(): void
    {
        self::assertSame([], $this->failuresOf("end\n"));
    }

    public function testEachListedFailureIsReadWithItsCheckAndReason(): void
    {
        self::assertEquals([
            new StartCheckFailure(StartCheck::Output, 'cannot write a probe file in /output'),
            new StartCheckFailure(StartCheck::Egress, 'api.example is not reachable through the proxy (curl exit 56)'),
        ], $this->failuresOf("output: cannot write a probe file in /output\negress: api.example is not reachable through the proxy (curl exit 56)\nend\n"));
    }

    public function testNoReportMeansTheOutputDirectoryCouldNotBeWritten(): void
    {
        self::assertSame([StartCheck::Output], $this->checksOf(null));
    }

    public function testAReportWithoutItsEndLineIsAFailedReport(): void
    {
        self::assertEquals(
            [new StartCheckFailure(StartCheck::Report, 'the report does not end with its end line')],
            $this->failuresOf("input: /input is missing\n"),
        );
    }

    public function testALineWithAnUnknownCheckIsAFailedReportNamingTheLine(): void
    {
        self::assertEquals([
            new StartCheckFailure(StartCheck::Work, 'cannot write a probe file in /work'),
            new StartCheckFailure(StartCheck::Report, 'line 2 of the report cannot be read'),
        ], $this->failuresOf("work: cannot write a probe file in /work\nall: fine\nend\n"));
    }

    public function testALineWithAByteOutsidePrintableAsciiIsAFailedReport(): void
    {
        self::assertSame([StartCheck::Report], $this->checksOf("input: /input/\xC3\xA9 is missing\nend\n"));
    }

    public function testAReasonIsCutTo255Bytes(): void
    {
        $failures = $this->failuresOf('input: '.str_repeat('a', 300)."\nend\n");

        self::assertSame(str_repeat('a', 255), $failures[0]->reason);
    }

    public function testAtMostTwentyFailuresAreRead(): void
    {
        self::assertCount(StartCheckReport::MAX_FAILURES, $this->failuresOf(str_repeat("input: /input is missing\n", 25)."end\n"));
    }

    public function testAReportAtTheSizeLimitIsRead(): void
    {
        $reason = str_repeat('a', 64 * 1024 - strlen("input: \nend\n"));

        self::assertSame([StartCheck::Input], $this->checksOf('input: '.$reason."\nend\n"));
    }

    public function testAReportOverTheSizeLimitIsRefusedWithoutBeingRead(): void
    {
        self::assertEquals(
            [new StartCheckFailure(StartCheck::Report, 'the report is larger than 65536 bytes')],
            $this->failuresOf(str_repeat('x', 64 * 1024 - 3)."end\n"),
        );
    }

    public function testALinkInPlaceOfTheReportIsNeverFollowed(): void
    {
        file_put_contents($this->output.'/elsewhere', "end\n");
        symlink($this->output.'/elsewhere', $this->output.'/'.StartCheckReport::FILE);

        self::assertSame([StartCheck::Report], $this->checksOf(null));
    }

    public function testADanglingLinkInPlaceOfTheReportIsAFailedReport(): void
    {
        symlink($this->output.'/absent', $this->output.'/'.StartCheckReport::FILE);

        self::assertSame([StartCheck::Report], $this->checksOf(null));
    }

    public function testAFolderInPlaceOfTheReportIsAFailedReport(): void
    {
        mkdir($this->output.'/'.StartCheckReport::FILE);

        self::assertSame([StartCheck::Report], $this->checksOf(null));
    }

    public function testAnUnreadableReportIsAFailedReport(): void
    {
        file_put_contents($this->output.'/'.StartCheckReport::FILE, "end\n");
        chmod($this->output.'/'.StartCheckReport::FILE, 0o000);

        self::assertEquals([new StartCheckFailure(StartCheck::Report, 'the report cannot be read')], @new StartCheckReport()->failuresIn($this->output));
    }

    /**
     * @return list<StartCheckFailure>
     */
    private function failuresOf(string $report): array
    {
        file_put_contents($this->output.'/'.StartCheckReport::FILE, $report);

        return new StartCheckReport()->failuresIn($this->output);
    }

    /**
     * @return list<StartCheck>
     */
    private function checksOf(?string $report): array
    {
        $failures = null === $report ? new StartCheckReport()->failuresIn($this->output) : $this->failuresOf($report);

        return array_map(static fn (StartCheckFailure $failure): StartCheck => $failure->check, $failures);
    }
}
