<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Infrastructure\DiffHunks;
use Intakt\Core\Review\Infrastructure\DiffPaths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DiffHunks::class)]
#[UsesClass(DiffPaths::class)]
final class DiffHunksTest extends TestCase
{
    public function testEachHunkCoversTheLinesItNamesInTheChangedFile(): void
    {
        $diff = "diff --git a/src/discount.js b/src/discount.js\n--- a/src/discount.js\n+++ b/src/discount.js\n@@ -10,6 +10,6 @@ function discount(total) {\n-  if (total >= 100) {\n+  if (total > 100) {\n@@ -40,2 +40,3 @@\n+x\n";

        self::assertSame([['src/discount.js', 10, 15], ['src/discount.js', 40, 42]], $this->hunksIn($diff));
    }

    public function testAHunkWithoutACountCoversOneLine(): void
    {
        self::assertSame([['src/A.php', 7, 7]], $this->hunksIn("diff --git a/src/A.php b/src/A.php\n@@ -7 +7 @@\n-a\n+b\n"));
    }

    public function testAHunkThatOnlyRemovesLinesCoversNone(): void
    {
        self::assertSame([], $this->hunksIn("diff --git a/src/A.php b/src/A.php\n@@ -7,2 +6,0 @@\n-a\n-b\n"));
    }

    public function testEachFileKeepsItsOwnHunks(): void
    {
        $diff = "diff --git a/src/A.php b/src/A.php\n@@ -1,2 +1,2 @@\n-a\n+b\ndiff --git a/src/B.php b/src/B.php\n@@ -5,1 +5,1 @@\n-a\n+b\n";

        self::assertSame([['src/A.php', 1, 2], ['src/B.php', 5, 5]], $this->hunksIn($diff));
    }

    public function testARenamedFileNamesItsHunksByItsNewPath(): void
    {
        $diff = "diff --git a/src/Old.php b/src/New.php\nsimilarity index 90%\nrename from src/Old.php\nrename to src/New.php\n--- a/src/Old.php\n+++ b/src/New.php\n@@ -3,2 +3,2 @@\n-a\n+b\n";

        self::assertSame([['src/New.php', 3, 4]], $this->hunksIn($diff));
    }

    public function testANewFileNamesItsHunkByItsPath(): void
    {
        $diff = "diff --git a/src/New.php b/src/New.php\nnew file mode 100644\n--- /dev/null\n+++ b/src/New.php\n@@ -0,0 +1,3 @@\n+a\n+b\n+c\n";

        self::assertSame([['src/New.php', 1, 3]], $this->hunksIn($diff));
    }

    // An added line "++ b/other" reads "+++ b/other" in the diff, and an added "@@ -1 +90 @@" reads "+@@ ...", so neither may name a file or a hunk.
    public function testAHunkLineThatLooksLikeAHeaderIsNeverReadAsOne(): void
    {
        $diff = "diff --git a/src/A.php b/src/A.php\n--- a/src/A.php\n+++ b/src/A.php\n@@ -1 +1,2 @@\n+++ b/src/Other.php\n+@@ -1 +90 @@\n";

        self::assertSame([['src/A.php', 1, 2]], $this->hunksIn($diff));
    }

    public function testADiffWithoutAFileHeaderHasNoHunks(): void
    {
        self::assertSame([], $this->hunksIn("@@ -1 +1 @@\n-a\n+b\n"));
    }

    /**
     * @return list<array{string, int, int}>
     */
    private function hunksIn(string $diff): array
    {
        return new DiffHunks(new DiffPaths())->in($diff);
    }
}
