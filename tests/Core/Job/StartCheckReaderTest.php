<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Intakt\Core\Job\Contract\StartCheckItem;
use Intakt\Core\Job\Contract\StartCheckReport;
use Intakt\Core\Job\Infrastructure\StartCheckReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(StartCheckReader::class)]
#[CoversClass(StartCheckReport::class)]
#[CoversClass(StartCheckItem::class)]
final class StartCheckReaderTest extends TestCase
{
    private string $folder = '';

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/intakt-check-'.bin2hex(random_bytes(4));
        new Filesystem()->mkdir($this->folder);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->folder);
    }

    public function testAnUnreachableVendorPutsTheJobInReducedModeAndNamesTheReason(): void
    {
        file_put_contents($this->folder.'/.start-check', "output\tok\t\nvendor\tfail\tthe vendor is not reachable through the proxy\n");

        $report = new StartCheckReader()->read(JobSpecBuilder::in($this->folder)->build());

        self::assertTrue($report->reduced());
        self::assertSame('vendor', $report->failed()[0]->name);
        self::assertSame('the vendor is not reachable through the proxy', $report->failed()[0]->reason);
    }

    public function testAJobWhoseEveryItemPassedIsNotReduced(): void
    {
        file_put_contents($this->folder.'/.start-check', "output\tok\t\ninput:/checkout\tok\t\n");

        $report = new StartCheckReader()->read(JobSpecBuilder::in($this->folder)->withoutEgress()->withInput('/srv/c', '/checkout')->build());

        self::assertFalse($report->reduced());
        self::assertCount(2, $report->items);
    }

    public function testAMissingReportFailsEveryExpectedItem(): void
    {
        $report = new StartCheckReader()->read(JobSpecBuilder::in($this->folder)->build());

        self::assertSame(['output', 'vendor'], array_map(static fn (StartCheckItem $item): string => $item->name, $report->failed()));
    }

    public function testAnItemTheJobLeftOutCountsAsFailedNotAsPassed(): void
    {
        file_put_contents($this->folder.'/.start-check', "output\tok\t\n");

        $report = new StartCheckReader()->read(JobSpecBuilder::in($this->folder)->build());

        self::assertSame('vendor', $report->failed()[0]->name);
    }

    public function testALinkInPlaceOfTheReportIsNotFollowed(): void
    {
        file_put_contents($this->folder.'/secret', "output\tok\t\nvendor\tok\t\n");
        symlink($this->folder.'/secret', $this->folder.'/.start-check');

        self::assertTrue(new StartCheckReader()->read(JobSpecBuilder::in($this->folder)->build())->reduced());
    }

    public function testAReportOverTheSizeLimitIsNotRead(): void
    {
        file_put_contents($this->folder.'/.start-check', "output\tok\t\nvendor\tok\t\n".str_repeat('x', 65536));

        self::assertTrue(new StartCheckReader()->read(JobSpecBuilder::in($this->folder)->build())->reduced());
    }

    public function testALineThatIsNotThreeFieldsOrWithAnUnknownStatusIsIgnored(): void
    {
        file_put_contents($this->folder.'/.start-check', "output\tmaybe\t\nvendor\tok\n");

        self::assertCount(2, new StartCheckReader()->read(JobSpecBuilder::in($this->folder)->build())->failed());
    }

    public function testAReasonIsCutAndLosesItsControlCharacters(): void
    {
        file_put_contents($this->folder.'/.start-check', "output\tfail\t\e[31m".str_repeat('r', 300)."\nvendor\tok\t\n");

        $reason = new StartCheckReader()->read(JobSpecBuilder::in($this->folder)->build())->failed()[0]->reason;

        self::assertSame(200, strlen($reason));
        self::assertStringStartsWith('?[31m', $reason);
    }
}
