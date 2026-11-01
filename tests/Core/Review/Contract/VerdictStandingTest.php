<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\IdentityField;
use Intakt\Core\Review\Contract\Standing;
use Intakt\Core\Review\Contract\VerdictStanding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VerdictStanding::class)]
final class VerdictStandingTest extends TestCase
{
    public function testACurrentVerdictNamesNoChangeAndNothingToReviewAgain(): void
    {
        $standing = VerdictStanding::current();

        self::assertSame([Standing::Current, [], []], [$standing->standing, $standing->changed, $standing->reviewAgain]);
    }

    public function testAKeptVerdictNamesWhatChangedAndThePathsToReviewAgain(): void
    {
        $standing = VerdictStanding::keptExceptFor([IdentityField::Head], ['docs/user/README.md']);

        self::assertSame([Standing::KeptForUntouchedParts, [IdentityField::Head], ['docs/user/README.md']], [$standing->standing, $standing->changed, $standing->reviewAgain]);
    }

    public function testAStaleVerdictNamesWhatChangedAndNoPathToReviewAgain(): void
    {
        $standing = VerdictStanding::stale([IdentityField::Base]);

        self::assertSame([Standing::Stale, [IdentityField::Base], []], [$standing->standing, $standing->changed, $standing->reviewAgain]);
    }
}
