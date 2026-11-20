<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Domain;

use Intakt\Core\Review\Contract\JudgeDone;
use Intakt\Core\Review\Contract\RecurringP1Found;
use Intakt\Core\Review\Domain\FlaggedRound;
use Intakt\Core\Review\Domain\RecurringP1;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecurringP1::class)]
#[CoversClass(FlaggedRound::class)]
#[UsesClass(JudgeDone::class)]
#[UsesClass(RecurringP1Found::class)]
final class RecurringP1Test extends TestCase
{
    private const string KEY = 'key-of-the-place';

    public function testAP1FlaggedAtThePlaceOfAnEarlierRoundOnAnotherHeadRecurs(): void
    {
        $found = new RecurringP1()->toRecord([$this->flagging(1, 'A'), $this->flagging(2, 'B')], [], []);

        self::assertEquals([new RecurringP1Found('round-2', 'F2', self::KEY)], $found);
    }

    public function testAP1FlaggedAgainOnTheSameHeadDoesNotRecur(): void
    {
        self::assertSame([], new RecurringP1()->toRecord([$this->flagging(1, 'A'), $this->flagging(2, 'A')], [], []));
    }

    public function testARecurrenceRecordedAlreadyIsNotRecordedAgain(): void
    {
        $recorded = [new RecurringP1Found('round-2', 'F2', self::KEY)];

        self::assertSame([], new RecurringP1()->toRecord([$this->flagging(1, 'A'), $this->flagging(2, 'B')], [], $recorded));
    }

    public function testHeadsABBJudgedInOrderRecordTwoRecurrences(): void
    {
        self::assertSame(['round-2', 'round-3'], $this->recordedJudging(['A', 'B', 'B'], [1, 2, 3]));
    }

    public function testHeadsABBJudgedInReverseOrderRecordTheSameTwoRecurrences(): void
    {
        self::assertSame(['round-2', 'round-3'], $this->recordedJudging(['A', 'B', 'B'], [3, 2, 1]));
    }

    public function testHeadsABAJudgedInOrderRecordOnlyTheRoundOnB(): void
    {
        self::assertSame(['round-2'], $this->recordedJudging(['A', 'B', 'A'], [1, 2, 3]));
    }

    public function testHeadsABAJudgedInReverseOrderRecordOnlyTheRoundOnB(): void
    {
        self::assertSame(['round-2'], $this->recordedJudging(['A', 'B', 'A'], [3, 2, 1]));
    }

    public function testHeadsABCJudgedInAnyOrderRecordTheRoundsOnBAndC(): void
    {
        self::assertSame(['round-2', 'round-3'], $this->recordedJudging(['A', 'B', 'C'], [2, 3, 1]));
    }

    public function testALaterRoundJudgedWhileAnEarlierRoundWaitsForItsJudgingRecordsNothingYet(): void
    {
        self::assertSame([], new RecurringP1()->toRecord([$this->flagging(2, 'B'), $this->flagging(3, 'A')], [1], []));
    }

    public function testARoundWaitingForItsJudgingAfterTheFirstToFlagHoldsNothingBack(): void
    {
        $found = new RecurringP1()->toRecord([$this->flagging(1, 'A'), $this->flagging(2, 'B')], [3], []);

        self::assertSame(['round-2'], $this->roundsOf($found));
    }

    public function testARecurrenceHeldBackByAnEarlierRoundIsRecordedOnceThatRoundsJudgingFails(): void
    {
        $rule = new RecurringP1();
        $judged = [$this->flagging(2, 'B'), $this->flagging(3, 'C')];
        $held = $rule->toRecord($judged, [1], []);

        $released = $rule->toRecord($judged, [], $held);

        self::assertSame([], $held);
        self::assertSame(['round-3'], $this->roundsOf($released));
    }

    public function testARoundJudgedAfterAnEarlierRoundFailedRecordsOnlyWhatTheFailureDidNotRecord(): void
    {
        $rule = new RecurringP1();
        $judged = [$this->flagging(2, 'B'), $this->flagging(3, 'C')];
        $recordedOnFailure = $rule->toRecord($judged, [], $rule->toRecord($judged, [1], []));

        $found = $rule->toRecord([...$judged, $this->flagging(4, 'D')], [], $recordedOnFailure);

        self::assertSame(['round-3', 'round-4'], $this->roundsOf([...$recordedOnFailure, ...$found]));
    }

    public function testAP1WithoutAKeyIsNeverFlagged(): void
    {
        $round = new FlaggedRound(1, 'A', new JudgeDone('round-1', ['F1'], [], [], p1Keys: ['F1' => null]));

        self::assertSame([], $round->places);
    }

    public function testAKeyedP1OffTheActListIsNotFlagged(): void
    {
        $round = new FlaggedRound(1, 'A', new JudgeDone('round-1', ['F1'], ['F2'], [], p1Keys: ['F2' => self::KEY]));

        self::assertSame([], $round->places);
    }

    public function testAPlaceFlaggedTwiceInARoundKeepsItsFirstFinding(): void
    {
        $round = new FlaggedRound(1, 'A', new JudgeDone('round-1', ['F1', 'F2'], [], [], p1Keys: ['F1' => self::KEY, 'F2' => self::KEY]));

        self::assertSame([self::KEY => 'F1'], $round->places);
    }

    public function testARoundAtAnotherPlaceDoesNotRecur(): void
    {
        $other = new FlaggedRound(1, 'A', new JudgeDone('round-1', ['F1'], [], [], p1Keys: ['F1' => 'another-key']));

        self::assertSame([], new RecurringP1()->toRecord([$other, $this->flagging(2, 'B')], [], []));
    }

    private function flagging(int $number, string $head): FlaggedRound
    {
        $finding = 'F'.$number;

        return new FlaggedRound($number, $head, new JudgeDone('round-'.$number, [$finding], [], [], p1Keys: [$finding => self::KEY]));
    }

    // The rounds not judged yet wait for their judging, because that is how a change's rounds meet the rule in a judging order.
    /**
     * @param list<string> $heads
     * @param list<int>    $order
     *
     * @return list<string>
     */
    private function recordedJudging(array $heads, array $order): array
    {
        $rule = new RecurringP1();
        $judged = [];
        $recorded = [];

        foreach ($order as $number) {
            $judged[] = $this->flagging($number, $heads[$number - 1]);
            $waiting = array_values(array_diff($order, array_map(static fn (FlaggedRound $round): int => $round->number, $judged)));
            $recorded = [...$recorded, ...$rule->toRecord($judged, $waiting, $recorded)];
        }

        return $this->roundsOf($recorded);
    }

    /**
     * @param list<RecurringP1Found> $found
     *
     * @return list<string>
     */
    private function roundsOf(array $found): array
    {
        $rounds = array_map(static fn (RecurringP1Found $one): string => $one->round, $found);
        sort($rounds);

        return $rounds;
    }
}
