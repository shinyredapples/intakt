<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\JudgedFinding;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\Mark;
use Intakt\Core\Review\Contract\Severity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Judgement::class)]
#[UsesClass(JudgedFinding::class)]
#[UsesClass(Finding::class)]
final class JudgementTest extends TestCase
{
    public function testAnUnreadableJudgementMarksEveryP1AndP2FindingAct(): void
    {
        $judgement = Judgement::everyBlockingFindingActs([$this->finding('a', Severity::P1), $this->finding('b', Severity::P2)]);

        self::assertEquals([new JudgedFinding('a', Mark::Act), new JudgedFinding('b', Mark::Act)], $judgement->marks);
    }

    public function testAnUnreadableJudgementMarksNoP3Finding(): void
    {
        $judgement = Judgement::everyBlockingFindingActs([$this->finding('a', Severity::P3), $this->finding('b', Severity::P2)]);

        self::assertEquals([new JudgedFinding('b', Mark::Act)], $judgement->marks);
    }

    private function finding(string $id, Severity $severity): Finding
    {
        return new Finding($id, $severity, null, null, null, 'Text.', FixableBy::Builder, false, null, null);
    }
}
