<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\Domain;

use Intakt\Core\HandBack\Domain\LostContent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LostContent::class)]
final class LostContentTest extends TestCase
{
    public function testAFileReplacedByASingleLineHasLostItsContent(): void
    {
        $lost = LostContent::in('docs/guide.md', $this->lines(120), "1\n");

        self::assertNotNull($lost);
        self::assertSame(['docs/guide.md', 120, 1], [$lost->path, $lost->linesBefore, $lost->linesAfter]);
    }

    public function testARemovedFileHasLostItsContent(): void
    {
        self::assertNotNull(LostContent::in('src/old.php', $this->lines(60), ''));
    }

    public function testAFileThatKeepsExactlyAFifthOfItsBytesHasNot(): void
    {
        $before = str_repeat("1234\n", 100);

        self::assertNull(LostContent::in('notes.txt', $before, str_repeat("1234\n", 20)));
        self::assertNotNull(LostContent::in('notes.txt', $before, str_repeat("1234\n", 19).'1234'));
    }

    public function testOnlyAFileThatKeepsUnderAFifthOfItsBytesCouldHaveLostItsContent(): void
    {
        self::assertFalse(LostContent::couldBeLost(500, 100));
        self::assertTrue(LostContent::couldBeLost(500, 99));
    }

    public function testAFileThatLosesExactlyFiftyLinesHasNot(): void
    {
        self::assertNull(LostContent::in('short.txt', $this->lines(50), ''));
        self::assertNotNull(LostContent::in('short.txt', $this->lines(51), ''));
    }

    public function testAFileThatShrinksByLessThanFourFifthsHasNot(): void
    {
        self::assertNull(LostContent::in('half.txt', $this->lines(200), $this->lines(100)));
    }

    public function testALongFileJoinedIntoFewLinesThatKeepsItsBytesHasNot(): void
    {
        $before = $this->lines(300);

        self::assertNull(LostContent::in('joined.txt', $before, str_replace("\n", ' ', $before)));
    }

    public function testALastLineWithoutItsLineBreakCounts(): void
    {
        $lost = LostContent::in('tail.txt', rtrim($this->lines(80)), '');

        self::assertSame(80, $lost?->linesBefore);
    }

    private function lines(int $count): string
    {
        return implode('', array_map(static fn (int $line): string => sprintf("line %03d of the file\n", $line), range(1, $count)));
    }
}
