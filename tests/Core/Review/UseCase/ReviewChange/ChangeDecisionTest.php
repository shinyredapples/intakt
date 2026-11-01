<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ReviewChange;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\UseCase\ReviewChange\ChangeDecision;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangeDecision::class)]
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

    private function vote(Decision $decision, Tier $tier = Tier::Standard): PanelVerdict
    {
        return new PanelVerdict($tier, new Verdict($decision, [], [], []));
    }
}
