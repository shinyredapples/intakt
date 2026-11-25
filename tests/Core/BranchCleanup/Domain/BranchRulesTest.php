<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\BranchCleanup\Domain;

use DateTimeImmutable;
use Intakt\Core\BranchCleanup\Domain\BranchFacts;
use Intakt\Core\BranchCleanup\Domain\BranchRules;
use Intakt\Core\BranchCleanup\Domain\BranchWarning;
use Intakt\Core\BranchCleanup\Domain\CleanupPolicy;
use Intakt\Core\BranchCleanup\Domain\Judgement;
use Intakt\Core\BranchCleanup\Domain\Verdict;
use Intakt\Plugin\Forge\Contract\BranchChange;
use Intakt\Plugin\Forge\Contract\BranchChangeState;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\ForgeBranch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BranchRules::class)]
#[CoversClass(Judgement::class)]
#[CoversClass(BranchFacts::class)]
#[UsesClass(CleanupPolicy::class)]
#[UsesClass(BranchWarning::class)]
#[UsesClass(BranchChange::class)]
#[UsesClass(ForgeBranch::class)]
#[UsesClass(CommitId::class)]
final class BranchRulesTest extends TestCase
{
    public function testABranchWithoutIntaktsPrefixIsOutOfBoundsUnlessTheProjectOptsIn(): void
    {
        $facts = $this->factsOf($this->namedBranch('feature/someone'), '-90 days', false);

        self::assertSame(Verdict::OutOfBounds, $this->rules()->verdictOf($facts, $this->now()));
    }

    public function testABranchWithoutIntaktsPrefixIsJudgedOnceTheProjectOptsIn(): void
    {
        $facts = $this->factsOf($this->namedBranch('feature/someone'), '-90 days', false);

        self::assertSame(Verdict::Warn, new BranchRules(new CleanupPolicy(true, []))->verdictOf($facts, $this->now()));
    }

    public function testTheDefaultAProtectedAndAReleaseBranchAreKeptEvenWithTheOptIn(): void
    {
        $rules = new BranchRules(new CleanupPolicy(true, []));
        $merged = new BranchChange(BranchChangeState::Merged);
        $branches = [
            new ForgeBranch('main', $this->head(), true, false, false),
            new ForgeBranch('guarded', $this->head(), false, true, false),
            new ForgeBranch('release/1.2', $this->head(), false, false, false),
            new ForgeBranch('release-1.4', $this->head(), false, false, false),
        ];

        foreach ($branches as $branch) {
            $facts = new BranchFacts($branch, $merged, $this->now()->modify('-90 days'), false);

            self::assertSame(Verdict::Keep, $rules->verdictOf($facts, $this->now()), $branch->name);
        }
    }

    public function testAMergedBranchIsDeletableAtOnceWithoutAWarning(): void
    {
        $facts = $this->facts('-1 hour', true, new BranchChange(BranchChangeState::Merged));

        self::assertSame(Verdict::Deletable, $this->rules()->verdictOf($facts, $this->now()));
    }

    public function testAClosedUnmergedBranchIsKeptUntilThirtyDaysHavePassed(): void
    {
        $facts = $this->closed('-29 days', []);

        self::assertSame(Verdict::Keep, $this->rules()->verdictOf($facts, $this->now()));
    }

    public function testAClosedUnmergedBranchIsToBeWarnedThirtyDaysAfterTheClose(): void
    {
        self::assertSame(Verdict::Warn, $this->rules()->verdictOf($this->closed('-30 days', []), $this->now()));
    }

    public function testAClosedUnmergedBranchIsDeletableAWeekAfterItsWarning(): void
    {
        $warning = new BranchWarning('intakt/7', $this->head(), $this->now()->modify('-7 days'));

        self::assertSame(Verdict::Deletable, $this->rules()->verdictOf($this->closed('-37 days', []), $this->now(), $warning));
    }

    public function testAClosedUnmergedBranchLabeledKeepIsNeverDeletable(): void
    {
        $warning = new BranchWarning('intakt/7', $this->head(), $this->now()->modify('-30 days'));

        self::assertSame(Verdict::Keep, $this->rules()->verdictOf($this->closed('-400 days', ['bug', 'keep']), $this->now(), $warning));
    }

    public function testABranchWithAnOpenPullRequestIsKeptHoweverOld(): void
    {
        $facts = $this->facts('-400 days', false, new BranchChange(BranchChangeState::Open));

        self::assertSame(Verdict::Keep, $this->rules()->verdictOf($facts, $this->now()));
    }

    public function testABranchWithoutAPullRequestIsKeptForTwentyNineDays(): void
    {
        self::assertSame(Verdict::Keep, $this->rules()->verdictOf($this->facts('-29 days', false), $this->now()));
    }

    public function testABranchWithoutAPullRequestIsStaleAtThirtyDays(): void
    {
        self::assertSame(Verdict::Stale, $this->rules()->verdictOf($this->facts('-30 days', false), $this->now()));
    }

    public function testABranchWithoutAPullRequestIsStillStaleJustBeforeSixtyDays(): void
    {
        self::assertSame(Verdict::Stale, $this->rules()->verdictOf($this->facts('-59 days', false), $this->now()));
    }

    public function testABranchWithoutAPullRequestIsToBeWarnedAtSixtyDays(): void
    {
        self::assertSame(Verdict::Warn, $this->rules()->verdictOf($this->facts('-60 days', false), $this->now()));
    }

    public function testABranchWithoutAPullRequestIsDeletableAWeekAfterItsWarning(): void
    {
        $warning = new BranchWarning('intakt/7', $this->head(), $this->now()->modify('-7 days'));

        self::assertSame(Verdict::Deletable, $this->rules()->verdictOf($this->facts('-67 days', false), $this->now(), $warning));
    }

    public function testABranchWithoutAPullRequestIsToBeWarnedAgainWhenItsHeadMovedAfterTheWarning(): void
    {
        $warning = new BranchWarning('intakt/7', new CommitId(str_repeat('b', 40)), $this->now()->modify('-8 days'));

        self::assertSame(Verdict::Warn, $this->rules()->verdictOf($this->facts('-68 days', false), $this->now(), $warning));
    }

    public function testABranchAPersonCommittedToIsOnlyStaleNeverDeletable(): void
    {
        $warning = new BranchWarning('intakt/7', $this->head(), $this->now()->modify('-30 days'));

        self::assertSame(Verdict::Stale, $this->rules()->verdictOf($this->facts('-90 days', true), $this->now(), $warning));
    }

    public function testABranchNamedInKeepBranchesWithoutAPullRequestIsOnlyStale(): void
    {
        $rules = new BranchRules(new CleanupPolicy(false, ['intakt/7']));

        self::assertSame(Verdict::Stale, $rules->verdictOf($this->facts('-90 days', false), $this->now()));
    }

    public function testKeepBranchesDoesNotClaimABranchWithAMergedPullRequest(): void
    {
        $rules = new BranchRules(new CleanupPolicy(false, ['intakt/7']));
        $facts = $this->facts('-1 day', false, new BranchChange(BranchChangeState::Merged));

        self::assertSame(Verdict::Deletable, $rules->verdictOf($facts, $this->now()));
    }

    public function testKeepBranchesDoesNotClaimABranchWithAClosedPullRequest(): void
    {
        $rules = new BranchRules(new CleanupPolicy(false, ['intakt/7']));

        self::assertSame(Verdict::Warn, $rules->verdictOf($this->closed('-30 days', []), $this->now()));
    }

    public function testAClaimCheckThatCannotAnswerClaimsTheBranch(): void
    {
        $warning = new BranchWarning('intakt/7', $this->head(), $this->now()->modify('-30 days'));

        self::assertSame(Verdict::Stale, $this->rules()->verdictOf($this->facts('-90 days', null), $this->now(), $warning));
    }

    private function namedBranch(string $name): ForgeBranch
    {
        return new ForgeBranch($name, $this->head(), false, false, false);
    }

    private function factsOf(ForgeBranch $branch, string $lastCommit, ?bool $byAPerson): BranchFacts
    {
        return new BranchFacts($branch, null, $this->now()->modify($lastCommit), $byAPerson);
    }

    private function rules(): BranchRules
    {
        return new BranchRules(new CleanupPolicy(false, []));
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-24T03:00:00Z');
    }

    private function head(): CommitId
    {
        return new CommitId(str_repeat('a', 40));
    }

    private function facts(string $lastCommit, ?bool $byAPerson, ?BranchChange $change = null): BranchFacts
    {
        return new BranchFacts($this->namedBranch('intakt/7'), $change, $this->now()->modify($lastCommit), $byAPerson);
    }

    /**
     * @param list<string> $labels
     */
    private function closed(string $closedAt, array $labels): BranchFacts
    {
        return new BranchFacts(
            $this->namedBranch('intakt/7'),
            new BranchChange(BranchChangeState::ClosedUnmerged, ...$labels),
            $this->now()->modify('-400 days'),
            false,
            $this->now()->modify($closedAt),
        );
    }
}
