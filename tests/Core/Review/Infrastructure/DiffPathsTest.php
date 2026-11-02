<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Infrastructure\DiffPaths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DiffPaths::class)]
final class DiffPathsTest extends TestCase
{
    public function testAnEditedFileIsReadFromItsHeader(): void
    {
        $paths = new DiffPaths()->in("diff --git a/src/Cart.php b/src/Cart.php\n--- a/src/Cart.php\n+++ b/src/Cart.php\n@@ -1 +1 @@\n-a\n+b\n");

        self::assertSame(['src/Cart.php'], $paths);
    }

    public function testARenamedFileGivesItsOldAndItsNewPath(): void
    {
        $paths = new DiffPaths()->in("diff --git a/src/Old.php b/src/New.php\nsimilarity index 100%\nrename from src/Old.php\nrename to src/New.php\n");

        self::assertSame(['src/Old.php', 'src/New.php'], $paths);
    }

    public function testARenameHeaderAloneStillGivesBothPaths(): void
    {
        self::assertSame(['src/Old.php', 'src/New.php'], new DiffPaths()->in("diff --git a/src/Old.php b/src/New.php\n"));
    }

    public function testAPathGitQuotedIsDecoded(): void
    {
        $paths = new DiffPaths()->in('diff --git "a/src/caf\303\251.php" "b/src/caf\303\251.php"'."\n");

        self::assertSame(['src/café.php'], $paths);
    }

    public function testAPathWithSpacesIsReadWhenBothSidesNameIt(): void
    {
        self::assertSame(['docs/my notes.md'], new DiffPaths()->in("diff --git a/docs/my notes.md b/docs/my notes.md\n"));
    }

    public function testADiffWithWindowsLineEndingsIsRead(): void
    {
        $paths = new DiffPaths()->in("diff --git a/src/Cart.php b/src/Cart.php\r\n--- a/src/Cart.php\r\n+++ b/src/Cart.php\r\n@@ -1 +1 @@\r\n-a\r\n+b\r\n");

        self::assertSame(['src/Cart.php'], $paths);
    }

    public function testANewFileGivesNoPathForItsMissingSide(): void
    {
        $paths = new DiffPaths()->in("diff --git a/src/New.php b/src/New.php\nnew file mode 100644\n--- /dev/null\n+++ b/src/New.php\n@@ -0,0 +1 @@\n+a\n");

        self::assertSame(['src/New.php'], $paths);
    }

    public function testAHunkLineThatLooksLikeAHeaderIsNotAPath(): void
    {
        $paths = new DiffPaths()->in("diff --git a/src/Cart.php b/src/Cart.php\n--- a/src/Cart.php\n+++ b/src/Cart.php\n@@ -1,2 +1 @@\n--- a/src/Secret.php\n+++ b/src/Other.php\n");

        self::assertSame(['src/Cart.php'], $paths);
    }

    public function testATextWithoutHeadersHasNoPaths(): void
    {
        self::assertSame([], new DiffPaths()->in('no diff here'));
    }
}
