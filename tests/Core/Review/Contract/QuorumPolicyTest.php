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
    /**
     * @param list<PanelVerdict> $votes
     */
    #[DataProvider('votesAndOutcomes')]
    public function testAPolicyIsMetOnlyByTheVotesItsRuleAccepts(QuorumPolicy $policy, array $votes, bool $met): void
    {
        self::assertSame($met, $policy->isMet($votes));
    }

    /**
     * @return iterable<string, array{QuorumPolicy, list<PanelVerdict>, bool}>
     */
    public static function votesAndOutcomes(): iterable
    {
        $approve = Decision::Approve;
        $changes = Decision::Changes;
        $invalid = new Verdict($changes, [new Finding(Verdict::INVALID_OUTPUT, Severity::P1, null, null, null, 'Broken.', FixableBy::Builder, false, null, null)], [], []);

        yield 'all is met by a panel of one approval' => [QuorumPolicy::All, [self::vote(Tier::Standard, $approve)], true];
        yield 'all is not met by a panel of one that asks for changes' => [QuorumPolicy::All, [self::vote(Tier::Standard, $changes)], false];
        yield 'all is not met by a panel of two where one asks for changes' => [QuorumPolicy::All, [self::vote(Tier::Strong, $approve), self::vote(Tier::Standard, $changes)], false];
        yield 'all is met by a panel of three approvals' => [QuorumPolicy::All, [self::vote(Tier::Strong, $approve), self::vote(Tier::Standard, $approve), self::vote(Tier::Light, $approve)], true];
        yield 'all is not met when one of three is blocked' => [QuorumPolicy::All, [self::vote(Tier::Strong, $approve), self::vote(Tier::Standard, $approve), self::vote(Tier::Light, Decision::Blocked)], false];
        yield 'a recorded invalid output verdict is no approval' => [QuorumPolicy::All, [self::vote(Tier::Strong, $approve), new PanelVerdict(Tier::Standard, $invalid)], false];
        yield 'majority is met by a panel of one approval' => [QuorumPolicy::Majority, [self::vote(Tier::Light, $approve)], true];
        yield 'majority is not met by a panel of one that asks for changes' => [QuorumPolicy::Majority, [self::vote(Tier::Light, $changes)], false];
        yield 'majority is not met by one approval out of two' => [QuorumPolicy::Majority, [self::vote(Tier::Strong, $approve), self::vote(Tier::Standard, $changes)], false];
        yield 'majority is met by two approvals out of three' => [QuorumPolicy::Majority, [self::vote(Tier::Standard, $approve), self::vote(Tier::Standard, $approve), self::vote(Tier::Strong, $changes)], true];
        yield 'majority is not met by one approval out of three' => [QuorumPolicy::Majority, [self::vote(Tier::Strong, $approve), self::vote(Tier::Standard, $changes), self::vote(Tier::Standard, Decision::Blocked)], false];
        yield 'one strong plus one is not met by a panel of one strong approval' => [QuorumPolicy::OneStrongPlusOne, [self::vote(Tier::Strong, $approve)], false];
        yield 'one strong plus one is met by a strong and a standard approval' => [QuorumPolicy::OneStrongPlusOne, [self::vote(Tier::Strong, $approve), self::vote(Tier::Standard, $approve)], true];
        yield 'one strong plus one is not met by two standard approvals' => [QuorumPolicy::OneStrongPlusOne, [self::vote(Tier::Standard, $approve), self::vote(Tier::Standard, $approve)], false];
        yield 'one strong plus one is not met when the strong reviewer asks for changes' => [QuorumPolicy::OneStrongPlusOne, [self::vote(Tier::Strong, $changes), self::vote(Tier::Standard, $approve)], false];
        yield 'one strong plus one is met by three where only the light reviewer asks for changes' => [QuorumPolicy::OneStrongPlusOne, [self::vote(Tier::Strong, $approve), self::vote(Tier::Standard, $approve), self::vote(Tier::Light, $changes)], true];
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

    private static function vote(Tier $tier, Decision $decision): PanelVerdict
    {
        return new PanelVerdict($tier, new Verdict($decision, [], [], []));
    }
}
