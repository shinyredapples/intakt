<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\BranchCleanup\Domain;

use DateTimeImmutable;
use Intakt\Core\BranchCleanup\Domain\BranchWarning;
use Intakt\Plugin\Forge\Contract\CommitId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BranchWarning::class)]
#[UsesClass(CommitId::class)]
final class BranchWarningTest extends TestCase
{
    public function testAWarningAtTwentyThreeFiftyNineDoesNotAllowDeletionSixDaysAndTwoMinutesLater(): void
    {
        $warning = $this->warningAt(new DateTimeImmutable('2026-11-01T23:59:00Z'));

        self::assertFalse($warning->allowsDeletionAt('intakt/7', $this->head('a'), new DateTimeImmutable('2026-11-08T00:01:00Z')));
    }

    public function testAWarningAllowsDeletionAtExactlySevenDays(): void
    {
        $warning = $this->warningAt(new DateTimeImmutable('2026-11-01T23:59:00Z'));

        self::assertTrue($warning->allowsDeletionAt('intakt/7', $this->head('a'), new DateTimeImmutable('2026-11-08T23:59:00Z')));
    }

    public function testAWarningDoesNotAllowDeletionOneSecondBeforeSevenDays(): void
    {
        $warning = $this->warningAt(new DateTimeImmutable('2026-11-01T23:59:00Z'));

        self::assertFalse($warning->allowsDeletionAt('intakt/7', $this->head('a'), new DateTimeImmutable('2026-11-08T23:58:59Z')));
    }

    public function testAWarningDoesNotAllowDeletionAfterTheHeadMoved(): void
    {
        $warning = $this->warningAt(new DateTimeImmutable('2026-11-01T00:00:00Z'));

        self::assertFalse($warning->allowsDeletionAt('intakt/7', $this->head('b'), new DateTimeImmutable('2026-12-01T00:00:00Z')));
    }

    public function testAWarningDoesNotAllowDeletionOfAnotherBranchAtTheSameHead(): void
    {
        $warning = $this->warningAt(new DateTimeImmutable('2026-11-01T00:00:00Z'));

        self::assertFalse($warning->allowsDeletionAt('intakt/8', $this->head('a'), new DateTimeImmutable('2026-12-01T00:00:00Z')));
    }

    private function warningAt(DateTimeImmutable $moment): BranchWarning
    {
        return new BranchWarning('intakt/7', $this->head('a'), $moment);
    }

    private function head(string $digit): CommitId
    {
        return new CommitId(str_repeat($digit, 40));
    }
}
