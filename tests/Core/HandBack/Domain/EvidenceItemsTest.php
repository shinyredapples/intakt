<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\Domain;

use Intakt\Core\HandBack\Domain\EvidenceItems;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EvidenceItems::class)]
final class EvidenceItemsTest extends TestCase
{
    public function testTheTopLevelItemsUnderAnEvidenceLabelAreCounted(): void
    {
        self::assertSame(2, EvidenceItems::countIn("Build it.\n\nEvidence:\n- E1. A red check.\n- E2. A green check.\n"));
    }

    public function testTheItemsUnderAnEvidenceHeadingAreCounted(): void
    {
        self::assertSame(3, EvidenceItems::countIn("## Acceptance\n- a\n\n## Evidence\n- E1: the test run\n* E2: the output\n1. a link (owner provides)\n"));
    }

    public function testAnItemWithoutABulletThatNamesItsNumberIsCounted(): void
    {
        self::assertSame(2, EvidenceItems::countIn("Evidence:\nE1. the test run\nE2) the output\n"));
    }

    public function testNestedLinesUnderAnItemAreNotItems(): void
    {
        self::assertSame(1, EvidenceItems::countIn("Evidence:\n- E1. Both results:\n  - a red one\n  - a green one\n    continued\n"));
    }

    public function testAParagraphAfterABlankLineEndsTheSection(): void
    {
        self::assertSame(1, EvidenceItems::countIn("Evidence:\n- E1. A check.\n\nOut of scope here:\n- the send-back\n- the ledger event\n"));
    }

    public function testTheNextHeadingOrLabelEndsTheSection(): void
    {
        self::assertSame(1, EvidenceItems::countIn("## Evidence\n- E1\n## Notes\n- not evidence\n"));
        self::assertSame(1, EvidenceItems::countIn("Evidence:\n- E1\nAcceptance:\n- not evidence\n"));
    }

    public function testALineDirectlyAfterAnItemContinuesIt(): void
    {
        self::assertSame(2, EvidenceItems::countIn("Evidence:\n- E1. A long item\nwrapped onto a second line.\n- E2. Another.\n"));
    }

    public function testAnEvidenceHeadingInsideACodeFenceIsNotTheSection(): void
    {
        self::assertSame(0, EvidenceItems::countIn("Example:\n```\n## Evidence\n- E1: the test run\n```\n"));
        self::assertSame(1, EvidenceItems::countIn("```\nEvidence:\n- quoted\n```\nEvidence:\n- E1. real\n"));
    }

    public function testATaskWithoutAnEvidenceSectionHasNoItems(): void
    {
        self::assertSame(0, EvidenceItems::countIn("Build it.\n\n- E1 is mentioned, but not under an Evidence heading\n"));
    }

    public function testWindowsLineBreaksAreLineBreaks(): void
    {
        self::assertSame(2, EvidenceItems::countIn("Evidence:\r\n- E1. one\r\n- E2. two\r\n"));
    }

    public function testOnlyTheFirstEvidenceSectionCounts(): void
    {
        self::assertSame(1, EvidenceItems::countIn("## Evidence\n- E1\n\n## Evidence\n- E1\n- E2\n"));
    }
}
