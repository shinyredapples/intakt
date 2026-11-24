<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\MarkVerdictsStale;

use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Review\Contract\IdentityField;
use Intakt\Core\Review\Contract\MarkVerdictsStale;
use Intakt\Core\Review\Contract\RoundVerdicts;
use Intakt\Core\Review\Contract\Standing;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Core\Review\Contract\VerdictsOfRound;
use Intakt\Core\Review\Contract\VerdictStanding;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\UseCase\MarkVerdictsStale\MarkVerdictsStaleHandler;
use Intakt\Core\Review\UseCase\PublishVerdict\PublishVerdict;
use Intakt\Core\Review\UseCase\ReadVerdicts\RoundNotFound;
use Intakt\Core\Review\UseCase\ReadVerdicts\VerdictsOfRoundHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Plugin\Forge\Contract\HeadMove;
use Intakt\Plugin\Forge\Contract\HeadMoveKind;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;

#[Group('persistence')]
#[CoversClass(MarkVerdictsStaleHandler::class)]
#[CoversClass(MarkVerdictsStale::class)]
#[CoversClass(ReviewRound::class)]
#[UsesClass(ReviewRounds::class)]
#[UsesClass(RoundNotFound::class)]
#[UsesClass(VerdictsOfRoundHandler::class)]
#[UsesClass(VerdictsOfRound::class)]
#[UsesClass(RoundVerdicts::class)]
#[UsesClass(VerdictIdentity::class)]
#[UsesClass(VerdictStanding::class)]
#[UsesClass(HeadMove::class)]
#[UsesClass(PublishVerdict::class)]
final class MarkVerdictsStaleTest extends KernelTestCase
{
    private RoundFixture $fixture;

    public function testARoundMarkedStaleHasStaleVerdictsEvenOnItsUnchangedIdentity(): void
    {
        $round = $this->fixture->start(2)->id()->toRfc4122();

        $this->handler()(new MarkVerdictsStale($round));

        $verdicts = $this->verdictsAtTheSameHead($round);
        self::assertSame(Standing::Stale, $verdicts->standing->standing);
        self::assertSame([IdentityField::Head], $verdicts->standing->changed);
        self::assertSame([], $verdicts->counting);
    }

    public function testMarkingOneRoundStaleLeavesTheVerdictsOfAnotherRoundCurrent(): void
    {
        $marked = $this->fixture->start(2)->id()->toRfc4122();
        $other = $this->fixture->startFor('8', $this->fixture->bus, 2);

        $this->handler()(new MarkVerdictsStale($marked));

        self::assertSame(Standing::Current, $this->verdictsAtTheSameHead($other)->standing->standing);
    }

    public function testAnUnknownRoundCannotBeMarkedStale(): void
    {
        $this->expectException(RoundNotFound::class);

        $this->handler()(new MarkVerdictsStale('not-a-round'));
    }

    public function testMarkingARoundStaleQueuesItsPublicationAgainSoAnApprovalAlreadyOnTheForgeIsWithdrawn(): void
    {
        $round = $this->fixture->start(2)->id()->toRfc4122();
        $commands = new QueuedPublications();

        new MarkVerdictsStaleHandler($this->fixture->transactions(), $commands)(new MarkVerdictsStale($round));

        self::assertEquals([new PublishVerdict($round)], $commands->messages);
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    private function handler(): MarkVerdictsStaleHandler
    {
        return new MarkVerdictsStaleHandler($this->fixture->transactions(), new QueuedPublications());
    }

    private function verdictsAtTheSameHead(string $round): RoundVerdicts
    {
        return $this->fixture->verdictsOf(new VerdictsOfRound($round, $this->fixture->identityAt(SampleIdentity::HEAD, 2), new HeadMove(HeadMoveKind::Unmoved, new Paths())));
    }
}
