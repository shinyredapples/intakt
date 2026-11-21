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
use Symfony\AI\Platform\Contract\JsonSchema\Factory;

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

    public function testOnlyTheFallbackIsMarkedAsTheFallback(): void
    {
        self::assertTrue(Judgement::everyBlockingFindingActs([$this->finding('a', Severity::P1)])->fallback());
        self::assertFalse(new Judgement([new JudgedFinding('a', Mark::Act)])->fallback());
        self::assertTrue(Judgement::everyBlockingFindingActs([$this->finding('a', Severity::P1)])->withMarks([])->fallback());
    }

    // The judge answers in this shape, so a flag a model could set must not be part of it.
    public function testTheAnswerSchemaHoldsOnlyTheMarks(): void
    {
        self::assertSame(['marks'], array_keys(new Factory()->buildProperties(Judgement::class)['properties'] ?? []));
    }

    private function finding(string $id, Severity $severity): Finding
    {
        return new Finding($id, $severity, null, null, null, 'Text.', FixableBy::Builder, false, null, null);
    }
}
