<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\Infrastructure;

use Intakt\Core\HandBack\Contract\HandBackServices;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

// Covers nothing because the default lives in config/services.yaml, which is not a class.
#[CoversNothing]
final class DependencySnapshotCountWiringTest extends KernelTestCase
{
    public function testTheStoreKeepsTenSnapshotsByDefault(): void
    {
        self::assertSame(10, self::getContainer()->getParameter(HandBackServices::DEPENDENCY_SNAPSHOTS));
    }
}
