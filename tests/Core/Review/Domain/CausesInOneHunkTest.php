<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Domain;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\JudgedFinding;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\Mark;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Domain\CausesInOneHunk;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CausesInOneHunk::class)]
#[UsesClass(Finding::class)]
#[UsesClass(JudgedFinding::class)]
#[UsesClass(Judgement::class)]
final class CausesInOneHunkTest extends TestCase
{
    private const array HUNKS = [['src/discount.js', 10, 15], ['src/discount.js', 40, 42], ['src/cart.js', 10, 15]];

    public function testALinkBetweenTwoFindingsInOneHunkIsKept(): void
    {
        self::assertSame('F1', $this->linkOf(['src/discount.js', 12], ['src/discount.js', 14]));
    }

    public function testALinkBetweenFindingsAtTheFirstAndLastLineOfAHunkIsKept(): void
    {
        self::assertSame('F1', $this->linkOf(['src/discount.js', 10], ['src/discount.js', 15]));
    }

    public function testALinkToALineJustPastTheHunkIsDropped(): void
    {
        self::assertNull($this->linkOf(['src/discount.js', 10], ['src/discount.js', 16]));
    }

    public function testALinkBetweenTwoHunksOfOneFileIsDropped(): void
    {
        self::assertNull($this->linkOf(['src/discount.js', 12], ['src/discount.js', 41]));
    }

    public function testALinkToTheSameLinesOfAnotherFileIsDropped(): void
    {
        self::assertNull($this->linkOf(['src/discount.js', 12], ['src/cart.js', 12]));
    }

    public function testALinkToAFindingWithoutALineIsDropped(): void
    {
        self::assertNull($this->linkOf(['src/discount.js', 12], ['src/discount.js', null]));
    }

    public function testALinkToAnIdThatWasNotGivenIsDropped(): void
    {
        $judgement = new Judgement([new JudgedFinding('F2', Mark::Act, 'F9')]);

        $checked = new CausesInOneHunk()->checked($judgement, [$this->finding('F2', 'src/discount.js', 12)], self::HUNKS);

        self::assertNull($checked->marks[0]->same_cause_as);
    }

    public function testADroppedLinkKeepsTheMark(): void
    {
        $judgement = new Judgement([new JudgedFinding('F1', Mark::Defer), new JudgedFinding('F2', Mark::Reject, 'F1')]);

        $checked = new CausesInOneHunk()->checked($judgement, [$this->finding('F1', 'src/discount.js', 12), $this->finding('F2', 'src/cart.js', 12)], self::HUNKS);

        self::assertEquals([new JudgedFinding('F1', Mark::Defer), new JudgedFinding('F2', Mark::Reject)], $checked->marks);
    }

    /**
     * @param array{string, ?int} $cause
     * @param array{string, ?int} $linked
     */
    private function linkOf(array $cause, array $linked): ?string
    {
        $judgement = new Judgement([new JudgedFinding('F1', Mark::Act), new JudgedFinding('F2', Mark::Act, 'F1')]);

        $checked = new CausesInOneHunk()->checked($judgement, [$this->finding('F1', ...$cause), $this->finding('F2', ...$linked)], self::HUNKS);

        return $checked->marks[1]->same_cause_as;
    }

    private function finding(string $id, string $file, ?int $line): Finding
    {
        return new Finding($id, Severity::P2, $file, $line, null, 'Broken.', FixableBy::Builder, false, null, null);
    }
}
