<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Disk;

use Intakt\Core\Disk\Contract\DiskHeadroom;
use Intakt\Core\Disk\Contract\DiskServices;
use Intakt\Core\Disk\Domain\FloorCheck;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

// Covers no class, because the defaults and the wiring live in config/services.yaml.
#[CoversNothing]
final class DiskFloorConfigTest extends KernelTestCase
{
    public function testTheFloorsHaveDefaultsAndTheHeadroomIsWired(): void
    {
        self::bootKernel();

        self::assertSame(1073741824, self::getContainer()->getParameter(DiskServices::FREE_BYTES_FLOOR));
        self::assertSame(10000, self::getContainer()->getParameter(DiskServices::FREE_INODES_FLOOR));
        self::assertInstanceOf(FloorCheck::class, self::getContainer()->get(DiskHeadroom::class));
    }
}
