<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Disk\Contract;

use Intakt\Core\Disk\Contract\DiskFloor;
use Intakt\Core\Disk\Contract\DiskHold;
use Intakt\Core\Disk\Contract\NewWorkHeld;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NewWorkHeld::class)]
#[UsesClass(DiskHold::class)]
final class NewWorkHeldTest extends TestCase
{
    public function testAHeldStepCarriesTheHoldAndSaysItInTheHoldsOwnWords(): void
    {
        $hold = new DiskHold(DiskFloor::Inodes, 'var/mirrors', 99, 100);

        $held = new NewWorkHeld($hold);

        self::assertSame($hold, $held->hold);
        self::assertSame('the disk of var/mirrors holds new work: 99 free inodes, below the floor of 100', $held->getMessage());
    }
}
