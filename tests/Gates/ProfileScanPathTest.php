<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ProfileScanPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProfileScanPath::class)]
final class ProfileScanPathTest extends TestCase
{
    public function testAnUnsetScanPathGetsTheDefaultDirectoriesAndThenTheProfile(): void
    {
        self::assertSame(':/repo/config/php', ProfileScanPath::missingFrom('/repo', null));
    }

    public function testTheProfileComesAfterTheDirectoriesAlreadyScannedSoItsSettingsWin(): void
    {
        self::assertSame(':/host/php-ini:/repo/config/php', ProfileScanPath::missingFrom('/repo', ':/host/php-ini'));
    }

    public function testAScanPathThatAlreadyListsTheProfileIsLeftAlone(): void
    {
        self::assertNull(ProfileScanPath::missingFrom('/repo', ':/host/php-ini:/repo/config/php'));
    }

    public function testAScanPathListingTheProfileWithATrailingSlashIsLeftAlone(): void
    {
        self::assertNull(ProfileScanPath::missingFrom('/repo', '/repo/config/php/'));
    }

    public function testADirectoryThatOnlyStartsLikeTheProfileDoesNotCount(): void
    {
        self::assertSame('/repo/config/php-old:/repo/config/php', ProfileScanPath::missingFrom('/repo', '/repo/config/php-old'));
    }
}
