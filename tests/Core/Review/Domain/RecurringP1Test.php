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
        $rule = new RecurringP1();
        $first = $this->flagging(1, 'A');
        $second = $this->flagging(2, 'B');
        $third = $this->flagging(3, 'B');

        $found = [...$rule->foundWhenJudged($first, []), ...$rule->foundWhenJudged($second, [$first]), ...$rule->foundWhenJudged($third, [$first, $second])];

        self::assertSame(['round-2', 'round-3'], $this->roundsOf($found));
    }

    public function testHeadsABBJudgedInReverseOrderRecordTheSameTwoRecurrences(): void
    {
        $rule = new RecurringP1();
        $first = $this->flagging(1, 'A');
        $second = $this->flagging(2, 'B');
        $third = $this->flagging(3, 'B');

        $found = [...$rule->foundWhenJudged($third, []), ...$rule->foundWhenJudged($second, [$third]), ...$rule->foundWhenJudged($first, [$third, $second])];

        self::assertSame(['round-2', 'round-3'], $this->roundsOf($found));
    }

    public function testALaterRoundThatRecurredAlreadyIsNotRecordedAgainWhenAnotherEarlierRoundIsJudged(): void
    {
        $found = new RecurringP1()->foundWhenJudged($this->flagging(1, 'A'), [$this->flagging(3, 'C'), $this->flagging(2, 'B')]);

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
