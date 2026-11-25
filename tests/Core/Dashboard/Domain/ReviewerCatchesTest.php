<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Domain\ChartBar;
use Intakt\Core\Dashboard\Domain\ChartScale;
use Intakt\Core\Dashboard\Domain\JudgeCall;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerCatch;
use Intakt\Core\Dashboard\Domain\ReviewerCatches;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Review\Contract\Severity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewerCatches::class)]
#[CoversClass(ReviewerCatch::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(ReviewerOutcome::class)]
#[UsesClass(RoundFinding::class)]
#[UsesClass(ChartBar::class)]
#[UsesClass(ChartScale::class)]
final class ReviewerCatchesTest extends TestCase
{
    public function testASingleReviewCountsEachRolesFindingsPerSeverity(): void
    {
        $round = $this->judged([$this->act('F1', 'P1'), $this->act('F2', 'P2'), $this->act('F3', 'P3')], ['security' => ['F1', 'F2'], 'correctness' => ['F3']]);

        $catches = ReviewerCatches::of([$round]);

        self::assertSame(['correctness' => [0, 0, 1], 'security' => [1, 1, 0]], $this->found($catches));
    }

    public function testAFindingOnlyOneReviewerReportedCountsAsFoundByItAlone(): void
    {
        $round = $this->judged([$this->act('F1', 'P1'), $this->act('F2', 'P1')], ['security' => ['F1', 'F2'], 'correctness' => ['F2']]);

        $catches = ReviewerCatches::of([$round]);

        self::assertSame(['correctness' => [0, 0, 0], 'security' => [1, 0, 0]], $this->alone($catches));
    }

    public function testAnEntryMergingTheFindingsOfTwoReviewersCountsForBothAndAloneForNeither(): void
    {
        $round = $this->judged([$this->act('F1', 'P1', ['F2'])], ['security' => ['F1'], 'correctness' => ['F2']], ['correctness' => ['F2' => 'P2']]);

        $catches = ReviewerCatches::of([$round]);

        self::assertSame(['correctness' => [0, 1, 0], 'security' => [1, 0, 0]], $this->found($catches));
        self::assertSame(['correctness' => [0, 0, 0], 'security' => [0, 0, 0]], $this->alone($catches));
    }

    public function testADroppedFindingCountsUnderItsSeverityAndAloneWhenNoOtherReviewerReportedIt(): void
    {
        $round = $this->judged([], ['security' => ['F1']], ['security' => ['F1' => 'P3']])->withDropped([new RoundFinding('F1', 'P3', 'Quotes code the change does not hold.')]);

        self::assertSame(['security' => [0, 0, 1]], $this->alone(ReviewerCatches::of([$round])));
    }

    public function testARoundWithAReporterWhoseRowRecordedNoSeveritiesIsLeftOut(): void
    {
        $round = $this->judged([$this->act('F1', 'P1')], ['security' => ['F1'], 'correctness' => []])->withSeveritiesByReviewer(['r-1-security' => ['F1' => 'P1']]);

        self::assertNull(ReviewerCatches::of([$round]));
    }

    public function testRolesAddUpOverRounds(): void
    {
        $first = $this->judged([$this->act('F1', 'P1')], ['security' => ['F1']]);
        $second = $this->judged([$this->act('F1', 'P1')], ['security' => ['F1']]);

        self::assertSame(['security' => [2, 0, 0]], $this->found(ReviewerCatches::of([$first, $second])));
    }

    public function testRoundsWithoutAReporterRecordGiveNoDataRatherThanZero(): void
    {
        $round = $this->round()->judgedWith([$this->act('F1', 'P1')], new DateTimeImmutable('2026-10-28T11:10:00+00:00'));

        self::assertNull(ReviewerCatches::of([$round]));
    }

    public function testAnUnjudgedRoundIsLeftOut(): void
    {
        $round = $this->round()->withOutcomes([$this->reviewer('security')])->withFindingsByReviewer(['security' => ['F1']]);

        self::assertNull(ReviewerCatches::of([$round]));
    }

    public function testARoundWithAReportedFindingOfUnknownSeverityIsLeftOutRatherThanGuessed(): void
    {
        $known = $this->judged([$this->act('F1', 'P1')], ['security' => ['F1']]);
        $unknown = $this->judged([new RoundFinding('F1', null, null)->calledBy(JudgeCall::Act)], ['security' => ['F1']]);

        self::assertSame(['security' => [1, 0, 0]], $this->found(ReviewerCatches::of([$known, $unknown])));
    }

    public function testARoundWhoseReporterRecordedAValueThatIsNoSeverityIsLeftOutRatherThanShownAsZeroBars(): void
    {
        $round = $this->judged([$this->act('F1', 'P1')], ['security' => ['F1']], ['security' => ['F1' => 'invalid']]);

        self::assertNull(ReviewerCatches::of([$round]));
    }

    public function testARoundWithAReporterWithoutARoleIsLeftOut(): void
    {
        $round = $this->round()->judgedWith([$this->act('F1', 'P1')], new DateTimeImmutable('2026-10-28T11:10:00+00:00'))->withFindingsByReviewer(['r-9' => ['F1']]);

        self::assertNull(ReviewerCatches::of([$round]));
    }

    public function testAReviewerThatReportedNothingStillHasItsZeroBars(): void
    {
        $round = $this->judged([$this->act('F1', 'P2')], ['security' => ['F1'], 'style' => []]);

        self::assertSame(['security' => [0, 1, 0], 'style' => [0, 0, 0]], $this->found(ReviewerCatches::of([$round])));
    }

    public function testTheTakeawayNamesTheReviewerWithTheMostP1sAndHowManyOnlyItFound(): void
    {
        $round = $this->judged([$this->act('F1', 'P1'), $this->act('F2', 'P1'), $this->act('F3', 'P1')], ['security' => ['F1', 'F2', 'F3'], 'correctness' => ['F2'], 'style' => []]);

        self::assertSame('The security reviewer found the most P1 findings, 3, and 2 of them no other reviewer found.', ReviewerCatches::of([$round])?->takeaway());
    }

    public function testTheTakeawayNamesEveryReviewerTiedAtTheTop(): void
    {
        $round = $this->judged([$this->act('F1', 'P1'), $this->act('F2', 'P1')], ['security' => ['F1'], 'correctness' => ['F2']]);

        self::assertSame('The correctness and security reviewers found the most P1 findings, 1 each; the correctness reviewer alone found 1 and the security reviewer alone found 1.', ReviewerCatches::of([$round])?->takeaway());
    }

    public function testTiedReviewersEachKeepTheirOwnCountOfP1sNoOtherReviewerFound(): void
    {
        $round = $this->judged([$this->act('F1', 'P1', ['F2', 'F3']), $this->act('F4', 'P1')], ['security' => ['F1', 'F4'], 'correctness' => ['F2', 'F3']], ['correctness' => ['F2' => 'P1', 'F3' => 'P1']]);

        self::assertSame('The correctness and security reviewers found the most P1 findings, 2 each; the correctness reviewer alone found 0 and the security reviewer alone found 1.', ReviewerCatches::of([$round])?->takeaway());
    }

    public function testAnEntryRaisedByAMergeCountsUnderTheSeverityItsOwnReviewerGave(): void
    {
        $round = $this->judged([$this->act('F1', 'P1', ['F2'])], ['correctness' => ['F1'], 'security' => ['F2']], ['correctness' => ['F1' => 'P2'], 'security' => ['F2' => 'P1']]);

        $catches = ReviewerCatches::of([$round]);

        self::assertSame(['correctness' => [0, 1, 0], 'security' => [1, 0, 0]], $this->found($catches));
        self::assertSame('The security reviewer found the most P1 findings, 1, and 0 of them no other reviewer found.', $catches?->takeaway());
    }

    public function testTwoReviewersRepeatingOneFindingAtDifferentSeveritiesEachCountTheirOwn(): void
    {
        $round = $this->judged([$this->act('F1', 'P2')], ['correctness' => ['F1'], 'security' => ['F1']], ['security' => ['F1' => 'P1']]);

        $catches = ReviewerCatches::of([$round]);

        self::assertSame(['correctness' => [0, 1, 0], 'security' => [1, 0, 0]], $this->found($catches));
        self::assertSame('The security reviewer found the most P1 findings, 1, and 0 of them no other reviewer found.', $catches?->takeaway());
    }

    public function testARoundWhoseReporterRowsHoldNoSeveritiesIsLeftOut(): void
    {
        $round = $this->round()
            ->withOutcomes([$this->reviewer('security')])
            ->judgedWith([$this->act('F1', 'P1')], new DateTimeImmutable('2026-10-28T11:10:00+00:00'))
            ->withFindingsByReviewer(['r-1-security' => ['F1']]);

        self::assertNull(ReviewerCatches::of([$round]));
    }

    public function testARoundWithAFindingMissingFromItsRecordIsLeftOut(): void
    {
        $round = $this->judged([$this->act('F1', 'P1')], ['security' => ['F1']])->withFindingsMissing();

        self::assertNull(ReviewerCatches::of([$round]));
    }

    public function testTheTakeawaySaysSoWhenNoReviewerFoundAP1(): void
    {
        $round = $this->judged([$this->act('F1', 'P3')], ['security' => ['F1']]);

        self::assertSame('No reviewer found a P1 finding.', ReviewerCatches::of([$round])?->takeaway());
    }

    public function testEachBarReadsItsCountFirstAndMarksTheShareFoundAlone(): void
    {
        $round = $this->judged([$this->act('F1', 'P1'), $this->act('F2', 'P1')], ['security' => ['F1', 'F2'], 'correctness' => ['F2']]);

        $bar = ReviewerCatches::of([$round])?->reviewers[1]->bars()[0];

        self::assertSame(['P1', 2, 1, '2 P1 findings, Security reviewer, 1 found by it alone'], [$bar?->label, $bar?->value, $bar?->part, $bar?->inWords()]);
    }

    public function testAScaleOverAReviewerThatReportedNothingStillHasATop(): void
    {
        $round = $this->judged([], ['security' => []]);

        self::assertSame(2, ReviewerCatches::of([$round])?->scaleTop());
    }

    public function testEveryMultipleSharesOneScale(): void
    {
        $round = $this->judged([$this->act('F1', 'P1'), $this->act('F2', 'P2'), $this->act('F3', 'P2'), $this->act('F4', 'P2')], ['security' => ['F1'], 'correctness' => ['F2', 'F3', 'F4']]);

        self::assertSame(4, ReviewerCatches::of([$round])?->scaleTop());
    }

    /**
     * @return array<string, list<int>>
     */
    private function alone(?ReviewerCatches $catches): array
    {
        return array_column(array_map(static fn (ReviewerCatch $reviewer): array => [$reviewer->role, array_map($reviewer->alone(...), Severity::cases())], $catches->reviewers ?? []), 1, 0);
    }

    private function round(): ReviewedRound
    {
        return new ReviewedRound(new RoundStart('r-1', 'alpha', 2), new RoundPlace('feat/thing', 7), new DateTimeImmutable('2026-10-28T11:00:00+00:00'), null);
    }

    private function reviewer(string $role): ReviewerOutcome
    {
        return new ReviewerOutcome('r-1-'.$role, $role, 'standard', new DateTimeImmutable('2026-10-28T11:00:00+00:00'));
    }

    /**
     * @param list<string> $merged
     */
    private function act(string $id, string $severity, array $merged = []): RoundFinding
    {
        return new RoundFinding($id, $severity, 'Text of '.$id.'.', $merged)->calledBy(JudgeCall::Act);
    }

    /**
     * @param list<RoundFinding>                   $findings
     * @param array<string, list<string>>          $reportedByRole
     * @param array<string, array<string, string>> $severityByRole
     */
    // Each reviewer rates an id as the entry of that id reads unless the test gives its own severity, the way a reporter row records it.
    private function judged(array $findings, array $reportedByRole, array $severityByRole = []): ReviewedRound
    {
        $entries = array_column(array_map(static fn (RoundFinding $finding): array => [$finding->id, $finding->severity], $findings), 1, 0);
        $severities = [];

        foreach ($reportedByRole as $role => $ids) {
            $rated = array_map(static fn (string $id): ?string => $severityByRole[$role][$id] ?? $entries[$id] ?? null, array_combine($ids, $ids));
            $severities['r-1-'.$role] = array_filter($rated, static fn (?string $severity): bool => null !== $severity);
        }

        return $this->round()
            ->withOutcomes(array_map($this->reviewer(...), array_keys($reportedByRole)))
            ->judgedWith($findings, new DateTimeImmutable('2026-10-28T11:10:00+00:00'))
            ->withSeveritiesByReviewer($severities)
            ->withFindingsByReviewer(array_combine(array_map(static fn (string $role): string => 'r-1-'.$role, array_keys($reportedByRole)), array_values($reportedByRole)));
    }

    /**
     * @return array<string, list<int>>
     */
    private function found(?ReviewerCatches $catches): array
    {
        return array_column(array_map(static fn (ReviewerCatch $reviewer): array => [$reviewer->role, array_map($reviewer->found(...), Severity::cases())], $catches->reviewers ?? []), 1, 0);
    }
}
