<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\BranchCleanup\Domain;

use DateTimeImmutable;
use Intakt\Core\BranchCleanup\Domain\BranchFacts;
use Intakt\Core\BranchCleanup\Domain\BranchRules;
use Intakt\Core\BranchCleanup\Domain\CleanupPolicy;
use Intakt\Core\BranchCleanup\Domain\Judgement;
use Intakt\Core\BranchCleanup\Domain\Verdict;
use Intakt\Core\Git\Contract\MirroredHead;
use Intakt\Plugin\Forge\Contract\BranchChange;
use Intakt\Plugin\Forge\Contract\BranchChangeState;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\ForgeBranch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BranchRules::class)]
#[CoversClass(CleanupPolicy::class)]
#[CoversClass(Judgement::class)]
#[CoversClass(BranchFacts::class)]
#[UsesClass(MirroredHead::class)]
#[UsesClass(BranchChange::class)]
#[UsesClass(ForgeBranch::class)]
#[UsesClass(CommitId::class)]
final class BranchRulesTest extends TestCase
{
    public function testOnlyBranchesWithIntaktsPrefixAreTouchableByDefault(): void
    {
        $rules = new BranchRules(new CleanupPolicy(false, []));

        self::assertSame(['intakt/7-add-intake'], $this->namesOf($rules->touchable([$this->branch('intakt/7-add-intake'), $this->branch('feature/someone')])));
    }

    public function testABranchWithoutIntaktsPrefixIsTouchableOnceTheProjectOptsIn(): void
    {
        $rules = new BranchRules(new CleanupPolicy(true, []));

        self::assertSame(['intakt/7-add-intake', 'feature/someone'], $this->namesOf($rules->touchable([$this->branch('intakt/7-add-intake'), $this->branch('feature/someone')])));
    }

    public function testTheDefaultAProtectedAndAReleaseBranchAreNeverTouchableEvenWithTheOptIn(): void
    {
        $rules = new BranchRules(new CleanupPolicy(true, []));
        $branches = [
            new ForgeBranch('intakt/main', $this->head(), true, false),
            new ForgeBranch('intakt/guarded', $this->head(), false, true),
            $this->branch('release/1.2'),
            $this->branch('releases/1.3'),
            $this->branch('release-1.4'),
        ];

        self::assertSame([], $rules->touchable($branches));
    }

    public function testABranchNamedLikeAReleaseOnlyInItsMiddleIsTouchable(): void
    {
        $rules = new BranchRules(new CleanupPolicy(true, []));

        self::assertSame(['intakt/release-notes'], $this->namesOf($rules->touchable([$this->branch('intakt/release-notes')])));
    }

    public function testAMergedBranchIsDueAtOnce(): void
    {
        $facts = $this->facts(new BranchChange(BranchChangeState::Merged, $this->now(), []), '-1 hour');

        self::assertSame(Verdict::Due, $this->rules()->verdictOf($facts, $this->now()));
    }

    public function testAClosedUnmergedBranchIsKeptUntilThirtyDaysHavePassed(): void
    {
        $facts = $this->facts(new BranchChange(BranchChangeState::ClosedUnmerged, $this->now()->modify('-29 days'), []), '-40 days');

        self::assertSame(Verdict::Keep, $this->rules()->verdictOf($facts, $this->now()));
    }

    public function testAClosedUnmergedBranchIsDueOnceThirtyDaysHavePassed(): void
    {
        $facts = $this->facts(new BranchChange(BranchChangeState::ClosedUnmerged, $this->now()->modify('-30 days'), []), '-40 days');

        self::assertSame(Verdict::Due, $this->rules()->verdictOf($facts, $this->now()));
    }

    public function testAClosedUnmergedBranchLabeledKeepIsNeverDue(): void
    {
        $facts = $this->facts(new BranchChange(BranchChangeState::ClosedUnmerged, $this->now()->modify('-400 days'), ['bug', 'keep']), '-400 days');

        self::assertSame(Verdict::Keep, $this->rules()->verdictOf($facts, $this->now()));
    }

    public function testABranchWithAnOpenPullRequestIsKeptHoweverOld(): void
    {
        $facts = $this->facts(new BranchChange(BranchChangeState::Open, null, []), '-400 days');

        self::assertSame(Verdict::Keep, $this->rules()->verdictOf($facts, $this->now()));
    }

    public function testABranchWithoutAPullRequestIsKeptForTwentyNineDays(): void
    {
        self::assertSame(Verdict::Keep, $this->rules()->verdictOf($this->facts(null, '-29 days'), $this->now()));
    }

    public function testABranchWithoutAPullRequestIsStaleAtThirtyDays(): void
    {
        self::assertSame(Verdict::Stale, $this->rules()->verdictOf($this->facts(null, '-30 days'), $this->now()));
    }

    public function testABranchWithoutAPullRequestIsDueAtSixtyDays(): void
    {
        self::assertSame(Verdict::Due, $this->rules()->verdictOf($this->facts(null, '-60 days'), $this->now()));
    }

    public function testABranchAPersonCommittedToIsOnlyStaleAtSixtyDays(): void
    {
        $facts = new BranchFacts($this->branch('intakt/7'), null, new MirroredHead($this->now()->modify('-90 days'), true));

        self::assertSame(Verdict::Stale, $this->rules()->verdictOf($facts, $this->now()));
    }

    public function testABranchNamedInKeepBranchesIsOnlyStaleAtSixtyDays(): void
    {
        $rules = new BranchRules(new CleanupPolicy(false, ['intakt/7']));

        self::assertSame(Verdict::Stale, $rules->verdictOf($this->facts(null, '-90 days'), $this->now()));
    }

    public function testABranchNamedInKeepBranchesIsStillDueWhenMerged(): void
    {
        $rules = new BranchRules(new CleanupPolicy(false, ['intakt/7']));
        $facts = $this->facts(new BranchChange(BranchChangeState::Merged, $this->now(), []), '-1 day');

        self::assertSame(Verdict::Due, $rules->verdictOf($facts, $this->now()));
    }

    public function testABranchWhoseHeadTheMirrorLacksIsKept(): void
    {
        $facts = new BranchFacts($this->branch('intakt/7'), new BranchChange(BranchChangeState::Merged, $this->now(), []), null);

        self::assertSame(Verdict::Keep, $this->rules()->verdictOf($facts, $this->now()));
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-24T03:00:00Z');
    }

    private function facts(?BranchChange $change, string $lastCommit): BranchFacts
    {
        return new BranchFacts($this->branch('intakt/7'), $change, new MirroredHead($this->now()->modify($lastCommit), false));
    }

    private function rules(): BranchRules
    {
        return new BranchRules(new CleanupPolicy(false, []));
    }

    private function branch(string $name): ForgeBranch
    {
        return new ForgeBranch($name, $this->head(), false, false);
    }

    private function head(): CommitId
    {
        return new CommitId(str_repeat('a', 40));
    }

    /**
     * @param list<ForgeBranch> $branches
     *
     * @return list<string>
     */
    private function namesOf(array $branches): array
    {
        return array_map(static fn (ForgeBranch $branch): string => $branch->name, $branches);
    }
}
