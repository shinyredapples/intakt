<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\OwnCgroup;
use Intakt\Gates\Processors;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Processors::class)]
#[UsesClass(OwnCgroup::class)]
final class ProcessorsTest extends TestCase
{
    public function testTheProcessorsAreCountedFromTheAffinityList(): void
    {
        self::assertSame(12, Processors::available("Cpus_allowed_list:\t0-11\n", 'max 100000'));
    }

    public function testACpuQuotaCapsTheProcessorsRoundedUp(): void
    {
        self::assertSame(2, Processors::available("Cpus_allowed_list:\t0-11\n", "150000 100000\n"));
    }

    public function testAnUnreadableStatusCountsOneProcessor(): void
    {
        self::assertSame(1, Processors::available('', ''));
    }

    public function testThisProcessSeesAtLeastOneProcessor(): void
    {
        self::assertGreaterThanOrEqual(1, Processors::available(Processors::status(), Processors::cpuMax()));
    }
}
