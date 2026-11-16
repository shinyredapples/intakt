<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\ReadAnswer;
use Intakt\Core\Review\Contract\ReadFinding;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReadAnswer::class)]
#[CoversClass(ReadFinding::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(Finding::class)]
final class ReadAnswerTest extends TestCase
{
    public function testAReadAnswerKeepsTheVerdictAndEachFindingsSeverityPlaceAndTitleInOrder(): void
    {
        $answer = ReadAnswer::of(new Verdict(Decision::Changes, [$this->finding(Severity::P1, 'src/Cart.php', 12, 'The discount ends early.'), $this->finding(Severity::P3, null, null, 'Name the constant.')], [], []));

        self::assertTrue($answer->wasRead());
        self::assertSame(Decision::Changes, $answer->verdict);
        self::assertEquals([new ReadFinding(Severity::P1, 'src/Cart.php', 12, 'The discount ends early.'), new ReadFinding(Severity::P3, null, null, 'Name the constant.')], $answer->findings);
    }

    public function testAFindingsTitleIsTheFirstLineOfItsText(): void
    {
        $read = ReadFinding::of($this->finding(Severity::P2, 'src/Cart.php', 3, "\n  The boundary is off by one.  \nIt should be inclusive.\n"));

        self::assertSame('The boundary is off by one.', $read->title);
    }

    public function testAnApprovalIsReadWithoutFindings(): void
    {
        $answer = ReadAnswer::of(new Verdict(Decision::Approve, [], [], []));

        self::assertTrue($answer->wasRead());
        self::assertSame([], $answer->findings);
    }

    public function testAnUnreadableAnswerCarriesNoVerdictAndNoFinding(): void
    {
        $answer = ReadAnswer::unreadable();

        self::assertFalse($answer->wasRead());
        self::assertNull($answer->verdict);
        self::assertSame([], $answer->findings);
    }

    private function finding(Severity $severity, ?string $file, ?int $line, string $text): Finding
    {
        return new Finding('F1', $severity, $file, $line, null, $text, FixableBy::Builder, false, null, null);
    }
}
