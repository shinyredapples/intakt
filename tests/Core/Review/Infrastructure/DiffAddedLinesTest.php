<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Infrastructure\DiffAddedLines;
use Intakt\Core\Review\Infrastructure\DiffPaths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DiffAddedLines::class)]
#[UsesClass(DiffPaths::class)]
final class DiffAddedLinesTest extends TestCase
{
    public function testAnAddedLineCarriesItsFileAndItsLineNumberInTheChangedFile(): void
    {
        $diff = "diff --git a/src/A.php b/src/A.php\n--- a/src/A.php\n+++ b/src/A.php\n@@ -10,3 +10,4 @@ function a()\n context\n-gone\n+first\n+second\n context\n";

        self::assertSame([['src/A.php', 11, 'first'], ['src/A.php', 12, 'second']], $this->addedIn($diff));
    }

    public function testARemovedOrContextLineIsNeverAnAddedLine(): void
    {
        self::assertSame([], $this->addedIn("diff --git a/src/A.php b/src/A.php\n@@ -1,2 +1,1 @@\n keep\n-gone\n\\ No newline at end of file\n"));
    }

    public function testEachHunkCountsFromItsOwnStart(): void
    {
        $diff = "diff --git a/src/A.php b/src/A.php\n@@ -1 +1 @@\n-a\n+b\n@@ -40 +40 @@\n-c\n+d\n";

        self::assertSame([['src/A.php', 1, 'b'], ['src/A.php', 40, 'd']], $this->addedIn($diff));
    }

    public function testEachFileKeepsItsOwnLines(): void
    {
        $diff = "diff --git a/src/A.php b/src/A.php\n@@ -1 +1 @@\n-a\n+b\ndiff --git a/src/B.php b/src/B.php\n@@ -5 +5 @@\n-c\n+d\n";

        self::assertSame([['src/A.php', 1, 'b'], ['src/B.php', 5, 'd']], $this->addedIn($diff));
    }

    public function testARenamedFilesLinesCarryTheNewPath(): void
    {
        $diff = "diff --git a/src/Old.php b/src/New.php\nsimilarity index 90%\nrename from src/Old.php\nrename to src/New.php\n--- a/src/Old.php\n+++ b/src/New.php\n@@ -3,1 +3,1 @@\n-a\n+b\n";

        self::assertSame([['src/New.php', 3, 'b']], $this->addedIn($diff));
    }

    public function testAnAddedLineThatLooksLikeAHeaderIsReadAsAnAddedLine(): void
    {
        $diff = "diff --git a/src/A.php b/src/A.php\n--- a/src/A.php\n+++ b/src/A.php\n@@ -1 +1,2 @@\n+++ b/src/Other.php\n+@@ -1 +90 @@\n";

        self::assertSame([['src/A.php', 1, '++ b/src/Other.php'], ['src/A.php', 2, '@@ -1 +90 @@']], $this->addedIn($diff));
    }

    public function testALineEndingInACarriageReturnLosesIt(): void
    {
        self::assertSame([['src/A.php', 1, 'b']], $this->addedIn("diff --git a/src/A.php b/src/A.php\r\n@@ -1 +1 @@\r\n-a\r\n+b\r\n"));
    }

    public function testADiffWithoutAFileHeaderHasNoAddedLines(): void
    {
        self::assertSame([], $this->addedIn("@@ -1 +1 @@\n-a\n+b\n"));
    }

    public function testAnAddedLineCarriesTheNewSideOfItsOwnHunkAndItsIndexInIt(): void
    {
        $diff = "diff --git a/src/A.php b/src/A.php\n@@ -1 +1 @@\n-a\n+b\n@@ -10,3 +10,4 @@\n keep\n-gone\n+first\n+second\n\n\\ No newline at end of file\n";

        $added = new DiffAddedLines(new DiffPaths())->in($diff);

        self::assertSame([['b', 0], ["keep\nfirst\nsecond\n", 1], ["keep\nfirst\nsecond\n", 2]], array_map(static fn (array $line): array => [$line[3], $line[4]], $added));
    }

    /**
     * @return list<array{string, int, string}>
     */
    private function addedIn(string $diff): array
    {
        return array_map(static fn (array $line): array => [$line[0], $line[1], $line[2]], new DiffAddedLines(new DiffPaths())->in($diff));
    }
}
