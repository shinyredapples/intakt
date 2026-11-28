<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ResolvedFinding;
use Intakt\Core\Review\Contract\ReviewLane;
use Intakt\Core\Review\Contract\Verdict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangeDecision::class)]
#[UsesClass(ReviewLane::class)]
#[UsesClass(QuorumPolicy::class)]
#[UsesClass(PanelVerdict::class)]
#[UsesClass(Verdict::class)]
final class ChangeDecisionTest extends TestCase
{
    public function testAMetQuorumApproves(): void
    {
        self::assertSame(Decision::Approve, new ChangeDecision()->of(QuorumPolicy::Majority, [$this->vote(Decision::Approve), $this->vote(Decision::Approve), $this->vote(Decision::Changes)]));
    }

    public function testAnUnmetQuorumWithAChangesVerdictAsksForChanges(): void
    {
        self::assertSame(Decision::Changes, new ChangeDecision()->of(QuorumPolicy::All, [$this->vote(Decision::Approve), $this->vote(Decision::Changes)]));
    }

    public function testAnUnmetQuorumWithNoChangesVerdictIsBlocked(): void
    {
        self::assertSame(Decision::Blocked, new ChangeDecision()->of(QuorumPolicy::All, [$this->vote(Decision::Approve), $this->vote(Decision::Blocked)]));
    }

    public function testAChangesVerdictDoesNotOverrideAMetQuorum(): void
    {
        self::assertSame(Decision::Approve, new ChangeDecision()->of(QuorumPolicy::OneStrongPlusOne, [$this->vote(Decision::Approve, Tier::Strong), $this->vote(Decision::Approve), $this->vote(Decision::Changes)]));
    }

    public function testAMissingVerdictCountedAsBlockedNeverHelpsAQuorum(): void
    {
        self::assertSame(Decision::Blocked, new ChangeDecision()->of(QuorumPolicy::OneStrongPlusOne, [$this->vote(Decision::Blocked, Tier::Strong), $this->vote(Decision::Approve)]));
    }

    public function testAStoredChangesVerdictWithAReservedFailureIdAloneAsksForChanges(): void
    {
        self::assertSame(Decision::Changes, new ChangeDecision()->of(QuorumPolicy::Majority, [$this->legacyVote()]));
    }

    public function testAStoredChangesVerdictWithAReservedFailureIdDoesNotOverrideAMetQuorum(): void
    {
        self::assertSame(Decision::Approve, new ChangeDecision()->of(QuorumPolicy::Majority, [$this->legacyVote(), $this->vote(Decision::Approve), $this->vote(Decision::Approve)]));
    }

    public function testAStoredChangesVerdictWithAReservedFailureIdAsksForChangesWhenAllMustApprove(): void
    {
        self::assertSame(Decision::Changes, new ChangeDecision()->of(QuorumPolicy::All, [$this->legacyVote(), $this->vote(Decision::Approve), $this->vote(Decision::Approve)]));
    }

    public function testATechnicalWriterAskingForChangesSendsTheChangeBackDespiteAMetQuorum(): void
    {
        self::assertSame(Decision::Changes, new ChangeDecision()->of(QuorumPolicy::Majority, [$this->vote(Decision::Approve), $this->vote(Decision::Approve), $this->docsVote(Decision::Changes)]));
    }

    public function testATechnicalWriterWithoutAVerdictHoldsTheChangeDespiteAMetQuorum(): void
    {
        self::assertSame(Decision::Blocked, new ChangeDecision()->of(QuorumPolicy::Majority, [$this->vote(Decision::Approve), $this->docsVote(Decision::Blocked)]));
    }

    public function testATechnicalWriterApprovalNeverStandsInForACodeReviewer(): void
    {
        self::assertSame(Decision::Blocked, new ChangeDecision()->of(QuorumPolicy::Majority, [$this->vote(Decision::Approve), $this->vote(Decision::Blocked), $this->docsVote(Decision::Approve)]));
    }

    public function testATechnicalWriterApprovalOnTopOfAMetQuorumApproves(): void
    {
        self::assertSame(Decision::Approve, new ChangeDecision()->of(QuorumPolicy::All, [$this->vote(Decision::Approve), $this->docsVote(Decision::Approve)]));
    }

    private function docsVote(Decision $decision): PanelVerdict
    {
        return new PanelVerdict(Tier::Standard, new Verdict($decision, [], [], []), ReviewLane::Docs);
    }

    private function legacyVote(): PanelVerdict
    {
        $legacy = new Verdict(Decision::Changes, Verdict::noTools()->findings, [new ResolvedFinding('F1')], []);
        self::assertTrue($legacy->isFailedReview(), 'The stored verdict does not read as a failed review.');

        return new PanelVerdict(Tier::Strong, $legacy);
    }

    private function vote(Decision $decision, Tier $tier = Tier::Standard): PanelVerdict
    {
        return new PanelVerdict($tier, new Verdict($decision, [], [], []));
    }
}
