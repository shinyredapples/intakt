<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\Infrastructure;

use Intakt\Core\HandBack\Contract\HandBackServices;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

// Covers no class, because the default lives in config/services.yaml, not in the store's code.
#[CoversNothing]
final class DependencySnapshotCountConfigTest extends KernelTestCase
{
    public function testTheInstallKeepsTenDependencySnapshotsByDefault(): void
    {
        self::bootKernel();

        self::assertSame(10, self::getContainer()->getParameter(HandBackServices::DEPENDENCY_SNAPSHOT_COUNT));
        self::assertStringEndsWith('/var/dependency-snapshots', self::getContainer()->getParameter(HandBackServices::DEPENDENCY_SNAPSHOTS_DIRECTORY));
    }
}
