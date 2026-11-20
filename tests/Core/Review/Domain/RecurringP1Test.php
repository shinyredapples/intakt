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
        $found = new RecurringP1()->foundWhenJudged($this->flagging(2, 'B'), [$this->flagging(1, 'A')]);

        self::assertEquals([new RecurringP1Found('round-2', 'F2', self::KEY)], $found);
    }

    public function testAP1FlaggedAgainOnTheSameHeadDoesNotRecur(): void
    {
        self::assertSame([], new RecurringP1()->foundWhenJudged($this->flagging(2, 'A'), [$this->flagging(1, 'A')]));
    }

    public function testAnEarlierRoundJudgedLastRecordsTheRecurrenceOfTheLaterRound(): void
    {
        $found = new RecurringP1()->foundWhenJudged($this->flagging(1, 'A'), [$this->flagging(2, 'B')]);

        self::assertEquals([new RecurringP1Found('round-2', 'F2', self::KEY)], $found);
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

    public function testALaterRoundJudgedWhileAnEarlierRoundMayStillBeJudgedRecordsNothingYet(): void
    {
        self::assertSame([], new RecurringP1()->foundWhenJudged($this->flagging(3, 'A'), [$this->flagging(2, 'B')], [1]));
    }

    public function testARoundThatMayStillBeJudgedAfterTheFirstToFlagHoldsNothingBack(): void
    {
        $found = new RecurringP1()->foundWhenJudged($this->flagging(2, 'B'), [$this->flagging(1, 'A')], [3]);

        self::assertSame(['round-2'], $this->roundsOf($found));
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

        self::assertSame([], new RecurringP1()->foundWhenJudged($this->flagging(2, 'B'), [$other]));
    }

    private function flagging(int $number, string $head): FlaggedRound
    {
        $finding = 'F'.$number;

        return new FlaggedRound($number, $head, new JudgeDone('round-'.$number, [$finding], [], [], p1Keys: [$finding => self::KEY]));
    }

    // The rounds not judged yet are passed as still to judge, because that is how a change's rounds meet the rule in a judging order.
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
        $found = [];

        foreach ($order as $number) {
            $pending = array_values(array_diff($order, [$number], array_map(static fn (FlaggedRound $round): int => $round->number, $judged)));
            $round = $this->flagging($number, $heads[$number - 1]);
            $found = [...$found, ...$rule->foundWhenJudged($round, $judged, $pending)];
            $judged[] = $round;
        }

        return $this->roundsOf($found);
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
