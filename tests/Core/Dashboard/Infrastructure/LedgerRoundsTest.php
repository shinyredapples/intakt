<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Domain\JudgeCall;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\ReviewerProgress;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Domain\TokenCount;
use Intakt\Core\Dashboard\Infrastructure\LedgerPayload;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LedgerTokens;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Shadow\Contract\ProjectMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(LedgerRounds::class)]
#[CoversClass(LedgerPayload::class)]
#[CoversClass(LedgerTokens::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(ReviewerOutcome::class)]
#[UsesClass(RoundFinding::class)]
#[UsesClass(RoundPlace::class)]
#[UsesClass(RoundStart::class)]
#[UsesClass(TokenCount::class)]
#[UsesClass(RecordedEvent::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(ProjectMode::class)]
final class LedgerRoundsTest extends TestCase
{
    private const string START = '{"round":"r1","project":"alpha","reviewers":2,"change":"feat/thing","number":1}';

    public function testAStartWithANumberThatIsNoIntegerIsSkipped(): void
    {
        $review = $this->reviewOf('r1', $this->event(LedgerEventKind::ReviewStarted, '{"round":"r1","project":"alpha","reviewers":2,"change":"feat/thing","number":"one"}'));

        self::assertNull($review);
    }

    public function testARepeatedStartDoesNotReplaceTheFirstReadableOne(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::ReviewStarted, '{"round":"r1","project":"beta","reviewers":5,"change":"feat/other","number":3}'),
        );

        self::assertSame(['alpha', 'feat/thing', 1], [$review?->project, $review?->change, $review?->rounds[0]->number]);
    }

    public function testAReviewerEventWithoutARoleIsSkipped(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::ReviewerStarted, '{"round":"r1","reviewer":"x","tier":"standard"}'),
        );

        self::assertSame([], $review?->rounds[0]->outcomes);
    }

    public function testAVerdictWithoutAFindingCountLeavesTheReviewerAsItWas(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::ReviewerStarted, '{"round":"r1","reviewer":"x","role":"correctness","tier":"standard"}'),
            $this->event(LedgerEventKind::VerdictReceived, '{"round":"r1","reviewer":"x","role":"correctness","tier":"standard","decision":"approve"}'),
        );

        self::assertSame(ReviewerProgress::Running, $review?->rounds[0]->outcomes[0]->progress);
    }

    #[DataProvider('modes')]
    public function testAVerdictCarriesItsRecordedModeAndReadsAsOrdinaryWithoutOne(string $field, ProjectMode $expected): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::VerdictReceived, '{"round":"r1","reviewer":"x","role":"correctness","tier":"standard","decision":"approve","findings":0'.$field.'}'),
        );

        self::assertSame($expected, $review?->rounds[0]->outcomes[0]->mode);
    }

    /**
     * @return iterable<string, array{string, ProjectMode}>
     */
    public static function modes(): iterable
    {
        yield 'shadow' => [',"mode":"shadow"', ProjectMode::Shadow];
        yield 'live' => [',"mode":"live"', ProjectMode::Live];
        yield 'recorded before the field existed' => ['', ProjectMode::Live];
        yield 'unknown value' => [',"mode":"other"', ProjectMode::Live];
        yield 'not a string' => [',"mode":3', ProjectMode::Live];
    }

    public function testAnEventOfAnotherKindWithReviewerFieldsAddsNoReviewer(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::AccountCooledDown, '{"round":"r1","reviewer":"x","role":"correctness","tier":"standard"}'),
        );

        self::assertSame([], $review?->rounds[0]->outcomes);
    }

    public function testAJudgedFindingWithoutTextIsSkippedAndTheOthersKeepTheirCall(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::JudgeDone, '{"round":"r1","act":["F2"],"follow_ups":[],"findings":[{"id":"F1","severity":"P1"},{"id":"F2","severity":"P1","text":"Kept."}]}'),
        );

        self::assertEquals([new RoundFinding('F2', 'P1', 'Kept.')->calledBy(JudgeCall::Act)], $review?->rounds[0]->findings);
    }

    public function testAMarkedIdWhoseFindingTextIsUnreadableKeepsItsCall(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::JudgeDone, '{"round":"r1","act":["F1"],"follow_ups":[],"findings":[{"id":"F1","severity":"P1"}]}'),
        );

        self::assertEquals([new RoundFinding('F1', null, null)->calledBy(JudgeCall::Act)], $review?->rounds[0]->findings);
    }

    public function testAJudgeDoneWithoutFindingsLeavesTheFindingTextUnrecorded(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::JudgeDone, '{"round":"r1","act":["F1"],"follow_ups":[]}'),
        );

        self::assertFalse($review?->rounds[0]->findingTextRecorded);
    }

    public function testAJudgeDoneWithFindingsRecordsTheFindingText(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::JudgeDone, '{"round":"r1","act":[],"follow_ups":[],"findings":[]}'),
        );

        self::assertTrue($review?->rounds[0]->findingTextRecorded);
    }

    public function testASecondJudgeDoneOfARoundChangesNothing(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::JudgeDone, '{"round":"r1","act":[],"follow_ups":[],"findings":[]}'),
            $this->event(LedgerEventKind::JudgeDone, '{"round":"r1","act":["F1"],"follow_ups":[],"findings":[{"id":"F1","severity":"P1","text":"Late."}]}'),
        );

        self::assertSame([], $review?->rounds[0]->findings);
    }

    public function testTokensSettledBeforeTheirStartedRowStillCountForTheRound(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::UsageRecorded, '{"call":"c1","phase":"unsettled","usages":[{"prompt":10,"completion":"many","total":12}]}'),
            $this->event(LedgerEventKind::UsageRecorded, '{"call":"c1","phase":"started","round":"r1"}'),
        );

        self::assertEquals(new TokenCount(10, 0, 12), $review?->rounds[0]->tokens);
    }

    private function reviewOf(string $round, RecordedEvent ...$events): ?Review
    {
        $rounds = new LedgerRounds();

        foreach ($events as $event) {
            $rounds->add($event);
        }

        return $rounds->reviewOf($round);
    }

    private function event(LedgerEventKind $kind, string $payload): RecordedEvent
    {
        return new RecordedEvent(Uuid::v7(), new LedgerEvent($kind, $payload), new DateTimeImmutable('2026-10-28T12:00:00+00:00'));
    }
}
