<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Domain\ChartBar;
use Intakt\Core\Dashboard\Domain\ChartScale;
use Intakt\Core\Dashboard\Domain\JudgeCall;
use Intakt\Core\Dashboard\Domain\JudgeOutcomes;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JudgeOutcomes::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(RoundFinding::class)]
#[UsesClass(ChartBar::class)]
#[UsesClass(ChartScale::class)]
final class JudgeOutcomesTest extends TestCase
{
    public function testEachFindingIsCountedUnderTheJudgesOutcome(): void
    {
        $round = $this->judged([
            $this->finding('F1', JudgeCall::Act, ['F2', 'F3']),
            $this->finding('F4', JudgeCall::Act),
            $this->finding('F5', JudgeCall::FollowUp),
            $this->finding('F6', JudgeCall::NotActedOn),
        ]);

        $outcomes = JudgeOutcomes::of([$round]);

        self::assertSame([2, 2, 1, 1, 6], [$outcomes?->kept, $outcomes?->merged, $outcomes?->deferred, $outcomes?->rejected, $outcomes?->total()]);
    }

    public function testOutcomesAddUpOverRounds(): void
    {
        $outcomes = JudgeOutcomes::of([$this->judged([$this->finding('F1', JudgeCall::Act)]), $this->judged([$this->finding('F1', JudgeCall::NotActedOn)])]);

        self::assertSame([1, 1], [$outcomes?->kept, $outcomes?->rejected]);
    }

    public function testAFindingMergedIntoTwoEntriesCountsOnce(): void
    {
        $outcomes = JudgeOutcomes::of([$this->judged([$this->finding('F1', JudgeCall::Act, ['F3']), $this->finding('F2', JudgeCall::Act, ['F3'])])]);

        self::assertSame(1, $outcomes?->merged);
    }

    public function testARoundJudgedFromMarksOnlyIsLeftOutSoNoUnmarkedFindingCountsAsRejected(): void
    {
        $marks = $this->round()->judgedFromMarksOnly([$this->finding('F1', JudgeCall::Act)], new DateTimeImmutable('2026-10-28T11:10:00+00:00'));

        self::assertNull(JudgeOutcomes::of([$marks]));
    }

    public function testARoundWithAnUnreadableFindingIsLeftOutSoTheRestNeverReadsAsTheWhole(): void
    {
        $round = $this->judged([$this->finding('F1', JudgeCall::Act)])->withFindingsMissing();

        self::assertNull(JudgeOutcomes::of([$round]));
    }

    public function testAFallbackRoundIsLeftOutSoItsUnmarkedP3AddsNothingToRejected(): void
    {
        $judged = $this->judged([$this->finding('F1', JudgeCall::NotActedOn)]);
        $fallback = $this->judged([$this->finding('F2', JudgeCall::Act), $this->finding('F3', JudgeCall::NotActedOn)])->judgedByFallback();

        $outcomes = JudgeOutcomes::of([$judged, $fallback]);

        self::assertSame([0, 1, 1], [$outcomes?->kept, $outcomes?->rejected, $outcomes?->total()]);
    }

    public function testARoundWrittenBeforeTheFallbackWasRecordedIsLeftOut(): void
    {
        $round = $this->round()->judgedWith([$this->finding('F1', JudgeCall::NotActedOn)], new DateTimeImmutable('2026-10-28T11:10:00+00:00'));

        self::assertNull(JudgeOutcomes::of([$round]));
    }

    public function testNoJudgedRoundGivesNoData(): void
    {
        self::assertNull(JudgeOutcomes::of([$this->round()]));
    }

    public function testAFindingDroppedBeforeTheJudgeIsNotTheJudgesOutcome(): void
    {
        $round = $this->judged([$this->finding('F1', JudgeCall::Act)])->withDropped([new RoundFinding('F2', 'P2', 'Quotes code the change does not hold.')]);

        self::assertSame(1, JudgeOutcomes::of([$round])?->total());
    }

    public function testTheTakeawaySaysHowManyFindingsTheJudgeKeptAndRejected(): void
    {
        $round = $this->judged([$this->finding('F1', JudgeCall::Act, ['F2']), $this->finding('F3', JudgeCall::FollowUp), $this->finding('F4', JudgeCall::NotActedOn), $this->finding('F5', JudgeCall::NotActedOn)]);

        self::assertSame('The judge kept 1 of 5 findings and rejected 2.', JudgeOutcomes::of([$round])?->takeaway());
    }

    public function testAJudgedRoundWithoutFindingsSaysTheJudgeHadNothingToJudge(): void
    {
        self::assertSame('The judge had no findings to judge yet.', JudgeOutcomes::of([$this->judged([])])?->takeaway());
    }

    public function testTheBarsNameEveryOutcomeWithItsCountFirst(): void
    {
        $outcomes = JudgeOutcomes::of([$this->judged([$this->finding('F1', JudgeCall::Act), $this->finding('F2', JudgeCall::NotActedOn), $this->finding('F3', JudgeCall::NotActedOn)])]);

        self::assertSame(
            ['1 finding, Kept to fix', '0 findings, Merged as duplicate', '0 findings, Deferred to a follow-up issue', '2 findings, Rejected'],
            array_map(static fn (ChartBar $bar): string => $bar->inWords(), $outcomes?->bars() ?? []),
        );
        self::assertSame(2, $outcomes?->scaleTop());
    }

    /**
     * @param list<RoundFinding> $findings
     */
    private function judged(array $findings): ReviewedRound
    {
        return $this->round()->judgedWith($findings, new DateTimeImmutable('2026-10-28T11:10:00+00:00'))->judgedOnItsOwnMarks();
    }

    private function round(): ReviewedRound
    {
        return new ReviewedRound(new RoundStart('r-1', 'alpha', 2), new RoundPlace('feat/thing', 7), new DateTimeImmutable('2026-10-28T11:00:00+00:00'), null);
    }

    /**
     * @param list<string> $merged
     */
    private function finding(string $id, JudgeCall $call, array $merged = []): RoundFinding
    {
        return new RoundFinding($id, 'P2', 'Text of '.$id.'.', $merged)->calledBy($call);
    }
}
