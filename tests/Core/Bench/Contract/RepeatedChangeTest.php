<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Bench\Contract;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Bench\Contract\BenchReview;
use Intakt\Core\Bench\Contract\BenchReviewerResult;
use Intakt\Core\Bench\Contract\RepeatedChange;
use Intakt\Core\Bench\Domain\FailureCause;
use Intakt\Core\Bench\Domain\RunVerdict;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\ModelNames;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\RoundTokens;
use Intakt\Core\Review\Contract\Severity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RepeatedChange::class)]
#[CoversClass(BenchReview::class)]
#[UsesClass(BenchReviewerResult::class)]
#[UsesClass(RunVerdict::class)]
#[UsesClass(RoundTokens::class)]
final class RepeatedChangeTest extends TestCase
{
    public function testRunsThatReachTheSameDecisionAndCatchTheSameDefectsAgree(): void
    {
        $change = new RepeatedChange('free-shipping', [$this->benchRun(Decision::Changes, $this->caught(['b', 'a'])), $this->benchRun(Decision::Changes, $this->caught(['a', 'b']))]);

        self::assertTrue($change->agreed());
    }

    public function testRunsWhereOneCatchesADefectTheOtherMissedDoNotAgree(): void
    {
        $change = new RepeatedChange('free-shipping', [$this->benchRun(Decision::Changes, $this->caught(['a'])), $this->benchRun(Decision::Changes, $this->caught([]))]);

        self::assertFalse($change->agreed());
    }

    public function testRunsWithDifferentDecisionsDoNotAgree(): void
    {
        $change = new RepeatedChange('free-shipping', [$this->benchRun(Decision::Changes, $this->caught(['a'])), $this->benchRun(Decision::Approve, $this->caught(['a']))]);

        self::assertFalse($change->agreed());
    }

    public function testRunsWhereOnlyOneRaisesAFalseAlarmDoNotAgree(): void
    {
        $alarm = new RunVerdict(true, [], [], [new Finding('F1', Severity::P2, 'src/total.js', 4, null, 'Wrong.', FixableBy::Builder, false, null, null)], [], null);

        $change = new RepeatedChange('round-total', [$this->benchRun(Decision::Approve, $this->caught([])), $this->benchRun(Decision::Approve, $alarm)]);

        self::assertFalse($change->agreed());
    }

    public function testARunWithAHarnessFailureIsLeftOutOfAgreement(): void
    {
        $failed = new RunVerdict(false, [], ['a' => FailureCause::Harness], [], [], 'stopped at its output limit');

        $change = new RepeatedChange('free-shipping', [$this->benchRun(Decision::Changes, $this->caught(['a'])), $this->benchRun(Decision::Blocked, $failed), $this->benchRun(Decision::Changes, $this->caught(['a']))]);

        self::assertTrue($change->agreed());
        self::assertCount(2, $change->comparedRuns());
    }

    public function testASingleRunIsNotComparable(): void
    {
        self::assertNull(new RepeatedChange('free-shipping', [$this->benchRun(Decision::Changes, $this->caught(['a']))])->agreed());
    }

    public function testTwoRunsLeftAsOneByAHarnessFailureAreNotComparable(): void
    {
        $failed = new RunVerdict(false, [], [], [], [], 'the reviewer gave no verdict');

        self::assertNull(new RepeatedChange('free-shipping', [$this->benchRun(Decision::Changes, $this->caught(['a'])), $this->benchRun(Decision::Blocked, $failed)])->agreed());
    }

    /**
     * @param list<string> $ids
     */
    private function caught(array $ids): RunVerdict
    {
        return new RunVerdict(false, $ids, [], [], [], null);
    }

    private function benchRun(Decision $decision, RunVerdict $verdict): BenchReview
    {
        return new BenchReview('free-shipping', $decision, [new BenchReviewerResult('role-1', Tier::Standard, $verdict, new RoundTokens(1, 100, 0, 0, 0, 10, 110), 1000, new ModelNames())], new ModelNames());
    }
}
