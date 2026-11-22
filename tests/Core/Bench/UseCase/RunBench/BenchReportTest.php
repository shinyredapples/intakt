<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Bench\UseCase\RunBench;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Bench\Contract\BenchReview;
use Intakt\Core\Bench\Contract\BenchReviewerResult;
use Intakt\Core\Bench\Contract\RepeatedChange;
use Intakt\Core\Bench\Domain\BenchFigures;
use Intakt\Core\Bench\Domain\RunVerdict;
use Intakt\Core\Bench\UseCase\RunBench\BenchReport;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\ModelNames;
use Intakt\Core\Review\Contract\RoundTokens;
use Intakt\Core\Review\Contract\Severity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BenchReport::class)]
#[CoversClass(BenchReviewerResult::class)]
#[UsesClass(BenchFigures::class)]
#[UsesClass(BenchReview::class)]
#[UsesClass(RepeatedChange::class)]
#[UsesClass(RunVerdict::class)]
#[UsesClass(Finding::class)]
#[UsesClass(RoundTokens::class)]
final class BenchReportTest extends TestCase
{
    public function testAFalseAlarmOnACleanChangeIsCausedByTheReviewerBeforeTheFigures(): void
    {
        $lines = $this->reportOf(new Finding('F1', Severity::P2, 'src/total.js', 4, null, 'Wrong finding.', FixableBy::Builder, false, null, null));

        self::assertSame(['failure causes:', '  clean run 1: reviewer (standard, model big) false alarm F1 at src/total.js:4, cause reviewer', 'figures:'], array_slice($lines, 0, 3));
    }

    public function testAFalseAlarmWithoutALineNamesItsFile(): void
    {
        $lines = $this->reportOf(new Finding('F1', Severity::P2, 'README.md', null, null, 'Wrong finding.', FixableBy::Builder, false, null, null));

        self::assertSame('  clean run 1: reviewer (standard, model big) false alarm F1 at README.md, cause reviewer', $lines[1]);
    }

    public function testAFalseAlarmWithoutAFileSaysItHasNoPlace(): void
    {
        $lines = $this->reportOf(new Finding('F1', Severity::P2, null, null, null, 'Wrong finding.', FixableBy::Builder, false, null, null));

        self::assertSame('  clean run 1: reviewer (standard, model big) false alarm F1 without a place, cause reviewer', $lines[1]);
    }

    public function testAHarnessFailureIsLeftOutOfTheDefectCountsButItsTokensAreTotaled(): void
    {
        $failed = new BenchReviewerResult('reviewer', Tier::Standard, new RunVerdict(false, [], [], [], [], 'tool failed'), new RoundTokens(1, 100, 0, 0, 0, 10, 110), 1000, new ModelNames('big'));

        $lines = new BenchReport()->linesOf([new RepeatedChange('example', [new BenchReview('example', Decision::Blocked, [$failed], [])])]);

        self::assertSame(
            '  reviewer (standard, model big), measured over 1 rounds: 0 of 0 planted defects found, 0 false alarms, 1 harness failures left out, 110 tokens (100 prompt, 10 completion, 1 calls)',
            array_last($lines),
        );
    }

    public function testAReviewerThatFailsBeforeAnyCallJoinsItsRoleAndTiersModelLineInTheTotals(): void
    {
        $lines = $this->totalsOf([['big'], []]);

        self::assertCount(1, $lines);
        self::assertStringStartsWith('  reviewer (standard, model big), measured over 2 rounds', $lines[0]);
    }

    public function testAReviewerWithNoRecordedModelKeepsItsOwnTotalsLineWhenItsRoleAndTierRanOnSeveralModels(): void
    {
        $lines = $this->totalsOf([['big'], ['small'], []]);

        self::assertCount(3, $lines);
        self::assertStringStartsWith('  reviewer (standard, model not recorded), measured over 1 rounds', $lines[2]);
    }

    /**
     * @param list<list<string>> $models
     *
     * @return list<string>
     */
    private function totalsOf(array $models): array
    {
        $results = array_map(static fn (array $named): BenchReviewerResult => new BenchReviewerResult('reviewer', Tier::Standard, new RunVerdict(false, [], [], [], [], null), new RoundTokens(1, 10, 0, 0, 0, 2, 12), 1, new ModelNames(...$named)), $models);
        $lines = new BenchReport()->linesOf([new RepeatedChange('example', [new BenchReview('example', Decision::Approve, $results, [])])]);

        return array_slice($lines, (int) array_search('totals per reviewer and model over every run:', $lines, true) + 1);
    }

    /**
     * @return list<string>
     */
    private function reportOf(Finding $alarm): array
    {
        $result = new BenchReviewerResult('reviewer', Tier::Standard, new RunVerdict(true, [], [], [$alarm], [], null), new RoundTokens(1, 10, 0, 0, 0, 2, 12), 1, new ModelNames('big'));

        return new BenchReport()->linesOf([new RepeatedChange('clean', [new BenchReview('clean', Decision::Changes, [$result], [])])]);
    }
}
