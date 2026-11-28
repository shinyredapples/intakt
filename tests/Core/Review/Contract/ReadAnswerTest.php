<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FindingKind;
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
#[UsesClass(FindingKind::class)]
final class ReadAnswerTest extends TestCase
{
    public function testAReadAnswerKeepsTheVerdictAndEachFindingsSeverityPlaceAndTitleInOrder(): void
    {
        $expected = [new ReadFinding(Severity::P1, 'src/Cart.php', 12, 'The discount ends early.', null), new ReadFinding(Severity::P3, null, null, 'Name the constant.', null)];

        $answer = ReadAnswer::of(new Verdict(Decision::Changes, array_map($this->findingReadAs(...), $expected), [], []));

        self::assertTrue($answer->wasRead());
        self::assertSame(Decision::Changes, $answer->verdict);
        self::assertEquals($expected, $answer->findings);
    }

    public function testAFindingsTitleIsTheReviewersTitleWhenItHasOne(): void
    {
        $read = ReadFinding::of(new Finding(
            'F1',
            Severity::P1,
            null,
            null,
            null,
            'The last item drops out of the total.',
            FixableBy::Builder,
            false,
            null,
            null,
            title: ' Total off by one ',
            kind: FindingKind::Logic,
        ));

        self::assertSame('Total off by one', $read->title);
    }

    public function testAFindingsKindIsKept(): void
    {
        $read = ReadFinding::of(new Finding(
            'F1',
            Severity::P1,
            null,
            null,
            null,
            'Broken.',
            FixableBy::Builder,
            false,
            null,
            null,
            title: 'Total off by one',
            kind: FindingKind::Logic,
        ));

        self::assertSame(FindingKind::Logic, $read->kind);
    }

    public function testAFindingRecordedWithoutATitleOrKindReadsAsTheFirstLineOfItsTextAndNoKind(): void
    {
        $read = ReadFinding::of(new Finding('F1', Severity::P1, null, null, null, "The boundary is off.\nMore.", FixableBy::Builder, false, null, null, title: " \u{00A0}"));

        self::assertSame(['The boundary is off.', null], [$read->title, $read->kind]);
    }

    public function testAFindingsTitleIsTheFirstLineOfItsText(): void
    {
        $read = ReadFinding::of(new Finding('F1', Severity::P2, 'src/Cart.php', 3, null, "\n  The boundary is off by one.  \nIt should be inclusive.\n", FixableBy::Builder, false, null, null));

        self::assertSame('The boundary is off by one.', $read->title);
    }

    public function testALongFirstLineIsKeptWholeForWhoeverShowsItToCut(): void
    {
        $line = 'Update **'.str_repeat('word ', 60).'end**.';

        $read = ReadFinding::of($this->findingWritten($line."\nThe rest."));

        self::assertSame($line, $read->title);
    }

    public function testAFindingsTitleEndsAtAnyLineEnding(): void
    {
        foreach (["\r\n", "\n", "\r"] as $lineEnding) {
            $read = ReadFinding::of($this->findingWritten('Copy *test.php.'.$lineEnding.'Keep build*.'));

            self::assertSame('Copy *test.php.', $read->title);
        }
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

    private function findingWritten(string $text): Finding
    {
        return new Finding('F1', Severity::P2, 'src/Cart.php', 3, null, $text, FixableBy::Builder, false, null, null);
    }

    private function findingReadAs(ReadFinding $read): Finding
    {
        return new Finding('F1', $read->severity, $read->file, $read->line, null, $read->title, FixableBy::Builder, false, null, null);
    }
}
