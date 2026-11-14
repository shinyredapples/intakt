<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QuorumPolicy::class)]
#[UsesClass(PanelVerdict::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(Finding::class)]
final class QuorumPolicyTest extends TestCase
{
    public function testAllIsMetByAPanelOfOneApproval(): void
    {
        self::assertTrue(QuorumPolicy::All->isMet([$this->vote(Tier::Standard, Decision::Approve)]));
    }

    public function testAllIsNotMetByAPanelOfOneThatAsksForChanges(): void
    {
        self::assertFalse(QuorumPolicy::All->isMet([$this->vote(Tier::Standard, Decision::Changes)]));
    }

    public function testAllIsNotMetByAPanelOfTwoWhereOneAsksForChanges(): void
    {
        $panel = [$this->vote(Tier::Strong, Decision::Approve), $this->vote(Tier::Standard, Decision::Changes)];

        self::assertFalse(QuorumPolicy::All->isMet($panel));
    }

    public function testAllIsMetByAPanelOfThreeApprovals(): void
    {
        $panel = [
            $this->vote(Tier::Strong, Decision::Approve),
            $this->vote(Tier::Standard, Decision::Approve),
            $this->vote(Tier::Light, Decision::Approve),
        ];

        self::assertTrue(QuorumPolicy::All->isMet($panel));
    }

    public function testAllIsNotMetWhenOneOfThreeIsBlocked(): void
    {
        $panel = [
            $this->vote(Tier::Strong, Decision::Approve),
            $this->vote(Tier::Standard, Decision::Approve),
            $this->vote(Tier::Light, Decision::Blocked),
        ];

        self::assertFalse(QuorumPolicy::All->isMet($panel));
    }

    public function testARecordedInvalidOutputVerdictIsNoApproval(): void
    {
        $invalid = new Verdict(Decision::Changes, [new Finding(Verdict::INVALID_OUTPUT, Severity::P1, null, null, null, 'Broken.', FixableBy::Builder, false, null, null)], [], []);
        $panel = [
            $this->vote(Tier::Strong, Decision::Approve),
            new PanelVerdict(Tier::Standard, $invalid),
        ];

        self::assertFalse(QuorumPolicy::All->isMet($panel));
    }

    public function testMajorityIsMetByAPanelOfOneApproval(): void
    {
        self::assertTrue(QuorumPolicy::Majority->isMet([$this->vote(Tier::Light, Decision::Approve)]));
    }

    public function testMajorityIsNotMetByAPanelOfOneThatAsksForChanges(): void
    {
        self::assertFalse(QuorumPolicy::Majority->isMet([$this->vote(Tier::Light, Decision::Changes)]));
    }

    public function testMajorityIsNotMetByOneApprovalOutOfTwo(): void
    {
        $panel = [$this->vote(Tier::Strong, Decision::Approve), $this->vote(Tier::Standard, Decision::Changes)];

        self::assertFalse(QuorumPolicy::Majority->isMet($panel));
    }

    public function testMajorityIsMetByTwoApprovalsOutOfThree(): void
    {
        $panel = [
            $this->vote(Tier::Standard, Decision::Approve),
            $this->vote(Tier::Standard, Decision::Approve),
            $this->vote(Tier::Strong, Decision::Changes),
        ];

        self::assertTrue(QuorumPolicy::Majority->isMet($panel));
    }

    public function testMajorityIsNotMetByOneApprovalOutOfThree(): void
    {
        $panel = [
            $this->vote(Tier::Strong, Decision::Approve),
            $this->vote(Tier::Standard, Decision::Changes),
            $this->vote(Tier::Standard, Decision::Blocked),
        ];

        self::assertFalse(QuorumPolicy::Majority->isMet($panel));
    }

    public function testOneStrongPlusOneIsNotMetByAPanelOfOneStrongApproval(): void
    {
        self::assertFalse(QuorumPolicy::OneStrongPlusOne->isMet([$this->vote(Tier::Strong, Decision::Approve)]));
    }

    public function testOneStrongPlusOneIsMetByAStrongAndAStandardApproval(): void
    {
        $panel = [$this->vote(Tier::Strong, Decision::Approve), $this->vote(Tier::Standard, Decision::Approve)];

        self::assertTrue(QuorumPolicy::OneStrongPlusOne->isMet($panel));
    }

    public function testOneStrongPlusOneIsNotMetByTwoStandardApprovals(): void
    {
        $panel = [$this->vote(Tier::Standard, Decision::Approve), $this->vote(Tier::Standard, Decision::Approve)];

        self::assertFalse(QuorumPolicy::OneStrongPlusOne->isMet($panel));
    }

    public function testOneStrongPlusOneIsNotMetWhenTheStrongReviewerAsksForChanges(): void
    {
        $panel = [$this->vote(Tier::Strong, Decision::Changes), $this->vote(Tier::Standard, Decision::Approve)];

        self::assertFalse(QuorumPolicy::OneStrongPlusOne->isMet($panel));
    }

    public function testOneStrongPlusOneIsMetByThreeWhereOnlyTheLightReviewerAsksForChanges(): void
    {
        $panel = [
            $this->vote(Tier::Strong, Decision::Approve),
            $this->vote(Tier::Standard, Decision::Approve),
            $this->vote(Tier::Light, Decision::Changes),
        ];

        self::assertTrue(QuorumPolicy::OneStrongPlusOne->isMet($panel));
    }

    #[DataProvider('policies')]
    public function testAnEmptyPanelMeetsNoPolicy(QuorumPolicy $policy): void
    {
        self::assertFalse($policy->isMet([]));
    }

    #[DataProvider('policies')]
    public function testAPolicyReadsBackFromItsConfigString(QuorumPolicy $policy): void
    {
        self::assertSame($policy, QuorumPolicy::from($policy->value));
    }

    /**
     * @return iterable<string, array{QuorumPolicy}>
     */
    public static function policies(): iterable
    {
        foreach (QuorumPolicy::cases() as $policy) {
            yield $policy->value => [$policy];
        }
    }

    private function vote(Tier $tier, Decision $decision): PanelVerdict
    {
        return new PanelVerdict($tier, new Verdict($decision, [], [], []));
    }
}
