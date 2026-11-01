<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\FollowUp;
use Intakt\Core\Review\Contract\JudgedFinding;
use Intakt\Core\Review\Contract\JudgedFindings;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\Mark;
use Intakt\Core\Review\Contract\Severity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JudgedFindings::class)]
#[CoversClass(FollowUp::class)]
#[UsesClass(Judgement::class)]
#[UsesClass(JudgedFinding::class)]
#[UsesClass(Finding::class)]
final class JudgedFindingsTest extends TestCase
{
    public function testTheActListHoldsOnlyFindingsMarkedAct(): void
    {
        $judged = JudgedFindings::of($this->marks(['a' => Mark::Act, 'b' => Mark::Defer, 'c' => Mark::Reject]), $this->findings('a', 'b', 'c'));

        self::assertEquals($this->findings('a'), $judged->actList);
    }

    public function testADeferredFindingBecomesAFollowUp(): void
    {
        $judged = JudgedFindings::of($this->marks(['a' => Mark::Act, 'b' => Mark::Defer]), $this->findings('a', 'b'));

        self::assertEquals([new FollowUp($this->findings('b')[0])], $judged->followUps);
    }

    public function testARejectedFindingIsNeitherOnTheActListNorAFollowUp(): void
    {
        $judged = JudgedFindings::of($this->marks(['a' => Mark::Reject]), $this->findings('a'));

        self::assertSame([], $judged->actList);
        self::assertSame([], $judged->followUps);
    }

    public function testAfterAnInvalidJudgeAnswerEveryBlockingFindingIsOnTheActListAndNoFollowUpIsMade(): void
    {
        $findings = [$this->finding('a', Severity::P1), $this->finding('b', Severity::P2), $this->finding('c', Severity::P3)];

        $judged = JudgedFindings::of(Judgement::everyBlockingFindingActs($findings), $findings);

        self::assertEquals([$findings[0], $findings[1]], $judged->actList);
        self::assertSame([], $judged->followUps);
    }

    public function testAFindingWithoutAMarkIsNeitherOnTheActListNorAFollowUp(): void
    {
        $judged = JudgedFindings::of($this->marks(['a' => Mark::Act]), $this->findings('a', 'unmarked'));

        self::assertEquals($this->findings('a'), $judged->actList);
        self::assertSame([], $judged->followUps);
    }

    /**
     * @param array<string, Mark> $marks
     */
    private function marks(array $marks): Judgement
    {
        $judged = [];

        foreach ($marks as $id => $mark) {
            $judged[] = new JudgedFinding((string) $id, $mark);
        }

        return new Judgement($judged);
    }

    /**
     * @return list<Finding>
     */
    private function findings(string ...$ids): array
    {
        return array_map(fn (string $id): Finding => $this->finding($id, Severity::P2), array_values($ids));
    }

    private function finding(string $id, Severity $severity): Finding
    {
        return new Finding($id, $severity, null, null, null, 'Text.', FixableBy::Builder, false, null, null);
    }
}
