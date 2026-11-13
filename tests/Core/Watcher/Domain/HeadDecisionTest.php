<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\Domain;

use DateTimeImmutable;
use Intakt\Core\Watcher\Domain\FailedBoot;
use Intakt\Core\Watcher\Domain\HeadAdmission;
use Intakt\Core\Watcher\Domain\HeadDecision;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\OpenChange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HeadDecision::class)]
#[CoversClass(FailedBoot::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(OpenChange::class)]
final class HeadDecisionTest extends TestCase
{
    public function testAGreenHeadSentBackForAFailedBootStartsNoRound(): void
    {
        $decision = $this->green()->sentBackFor(new FailedBoot('The server did not start.'));

        self::assertSame(HeadAdmission::FailedBoot, $decision->admission);
        self::assertFalse($decision->startsRound());
    }

    public function testAHeadSentBackForAFailedBootKeepsItsChangeAndCarriesTheReason(): void
    {
        $green = $this->green();

        $decision = $green->sentBackFor(new FailedBoot('The server did not start.'));

        self::assertSame($green->change, $decision->change);
        self::assertSame('The server did not start.', $decision->failedBoot?->reason);
    }

    public function testAGreenHeadCarriesNoFailedBootAndStartsARound(): void
    {
        $green = $this->green();

        self::assertNull($green->failedBoot);
        self::assertTrue($green->startsRound());
    }

    private function green(): HeadDecision
    {
        return new HeadDecision(new OpenChange(new ChangeId('7'), new CommitId(str_repeat('b', 40)), new DateTimeImmutable('2026-11-13T10:00:00+00:00')), HeadAdmission::Green);
    }
}
