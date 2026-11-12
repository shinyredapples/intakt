<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\RoundOpened;
use Intakt\Core\Review\Contract\Standing;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Core\Review\Contract\VerdictsOfRound;
use Intakt\Core\Review\Contract\VerdictStanding;
use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Domain\ReviewerTransition;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\UseCase\ReadVerdicts\VerdictsOfRoundHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRoundHandler;
use Intakt\Core\Shadow\Infrastructure\ConfiguredProjectModes;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitRange;
use Intakt\Plugin\Forge\Contract\HeadMove;
use Intakt\Plugin\Forge\Contract\HeadMoveKind;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;

#[Group('persistence')]
#[CoversClass(RunReviewRoundHandler::class)]
#[CoversClass(RoundLedger::class)]
#[CoversClass(VerdictsOfRoundHandler::class)]
#[UsesClass(ConfiguredProjectModes::class)]
#[UsesClass(VerdictsOfRound::class)]
#[UsesClass(HeadMove::class)]
#[UsesClass(ReviewRounds::class)]
#[UsesClass(RoundState::class)]
#[UsesClass(ReviewerState::class)]
#[UsesClass(ReviewerTransition::class)]
#[UsesClass(RoundOpened::class)]
#[UsesClass(VerdictIdentity::class)]
#[UsesClass(VerdictStanding::class)]
#[UsesClass(PanelVerdict::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(CommitRange::class)]
final class ShadowProjectRoundTest extends KernelTestCase
{
    private RoundFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer(), new ConfiguredProjectModes(['alpha' => 'shadow']));
    }

    public function testAShadowProjectsRoundStartsWithoutBeingHeld(): void
    {
        $this->fixture->startHandlerOn($this->fixture->bus)($this->fixture->commandFor(RoundFixture::CHANGE, 2));

        self::assertSame([LedgerEventKind::ReviewStarted], $this->fixture->ledgerKinds());
    }

    public function testAShadowProjectsVerdictsCountLikeAnyOtherRounds(): void
    {
        $id = $this->fixture->startHandlerOn($this->fixture->bus)($this->fixture->commandFor(RoundFixture::CHANGE, 2));
        $vendor = new ScriptedVendor();
        foreach ($this->fixture->bus->messages as $message) {
            $this->fixture->deliver($message, $vendor);
        }

        $verdicts = $this->fixture->verdictsOf(new VerdictsOfRound($id, $this->fixture->identityAt(SampleIdentity::HEAD, 2), new HeadMove(HeadMoveKind::Unmoved, [])));

        self::assertSame(Standing::Current, $verdicts->standing->standing);
        self::assertCount(2, $verdicts->counting);
    }
}
