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

        self::assertSame([['src/A.php', 11], ['src/A.php', 12]], $this->addedIn($diff));
    }

    public function testARemovedOrContextLineIsNeverAnAddedLine(): void
    {
        self::assertSame([], $this->addedIn("diff --git a/src/A.php b/src/A.php\n@@ -1,2 +1,1 @@\n keep\n-gone\n\\ No newline at end of file\n"));
    }

    public function testEachHunkCountsFromItsOwnStart(): void
    {
        $diff = "diff --git a/src/A.php b/src/A.php\n@@ -1 +1 @@\n-a\n+b\n@@ -40 +40 @@\n-c\n+d\n";

        self::assertSame([['src/A.php', 1], ['src/A.php', 40]], $this->addedIn($diff));
    }

    public function testEachFileKeepsItsOwnLines(): void
    {
        $diff = "diff --git a/src/A.php b/src/A.php\n@@ -1 +1 @@\n-a\n+b\ndiff --git a/src/B.php b/src/B.php\n@@ -5 +5 @@\n-c\n+d\n";

        self::assertSame([['src/A.php', 1], ['src/B.php', 5]], $this->addedIn($diff));
    }

    public function testARenamedFilesLinesCarryTheNewPath(): void
    {
        $diff = "diff --git a/src/Old.php b/src/New.php\nsimilarity index 90%\nrename from src/Old.php\nrename to src/New.php\n--- a/src/Old.php\n+++ b/src/New.php\n@@ -3,1 +3,1 @@\n-a\n+b\n";

        self::assertSame([['src/New.php', 3]], $this->addedIn($diff));
    }

    public function testAnAddedLineThatLooksLikeAHeaderIsReadAsAnAddedLine(): void
    {
        $diff = "diff --git a/src/A.php b/src/A.php\n--- a/src/A.php\n+++ b/src/A.php\n@@ -1 +1,2 @@\n+++ b/src/Other.php\n+@@ -1 +90 @@\n";

        self::assertSame([['src/A.php', 1], ['src/A.php', 2]], $this->addedIn($diff));
    }

    public function testABlankContextLineWithACarriageReturnCountsAsALine(): void
    {
        self::assertSame([['src/A.php', 3]], $this->addedIn("diff --git a/src/A.php b/src/A.php\r\n@@ -1,2 +1,3 @@\r\n keep\r\n\r\n+b\r\n"));
    }

    public function testABlankContextLineWhoseSpaceWasTrimmedCountsAsALine(): void
    {
        self::assertSame([['src/A.php', 12]], $this->addedIn("diff --git a/src/A.php b/src/A.php\n@@ -10,2 +10,3 @@\n keep\n\n+b\n\\ No newline at end of file\n"));
    }

    public function testADiffWithoutAFileHeaderHasNoAddedLines(): void
    {
        self::assertSame([], $this->addedIn("@@ -1 +1 @@\n-a\n+b\n"));
    }

    /**
     * @return list<array{string, int}>
     */
    private function addedIn(string $diff): array
    {
        return new DiffAddedLines(new DiffPaths())->in($diff);
    }
}
