<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\LocalGitForge\Infrastructure\BinaryFiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BinaryFiles::class)]
#[UsesClass(ForgeReadFailed::class)]
final class BinaryFilesTest extends TestCase
{
    private const string BASE = '1111111111111111111111111111111111111111';
    private const string HEAD = '2222222222222222222222222222222222222222';
    private const string TEXT = "diff --git a/a.txt b/a.txt\nindex 3333333333333333333333333333333333333333..4444444444444444444444444444444444444444 100644\n--- a/a.txt\n+++ b/a.txt\n@@ -1 +1 @@\n-old\n+diff --git a/b.txt b/b.txt\n";

    public function testAPatchOfTextFilesIsLeftAsItIs(): void
    {
        $named = new BinaryFiles()->named(self::TEXT, static fn (string $id): string => "old\n");

        self::assertSame(self::TEXT, $named);
    }

    public function testAFileWithANulByteInABlobKeepsItsHeaderAndIsNamedWithTheSizesOfBothBlobs(): void
    {
        $binary = "diff --git a/b.bin b/b.bin\nindex ".self::BASE.'..'.self::HEAD." 100644\n--- a/b.bin\n+++ b/b.bin\n@@ -1 +1 @@\n-\0\n+\xff\n";

        $named = new BinaryFiles()->named(self::TEXT.$binary, static fn (string $id): string => match ($id) {
            self::BASE => "\0",
            self::HEAD => "\xff\0",
            default => "old\n",
        });

        self::assertSame(self::TEXT."diff --git a/b.bin b/b.bin\nindex ".self::BASE.'..'.self::HEAD." 100644\nBinary file, not shown: 1 byte at the base, 2 bytes at the head\n", $named);
    }

    public function testAFileIsNamedAsBinaryWhenItsNulByteLiesOutsideTheLinesShown(): void
    {
        $file = "diff --git a/i.png b/i.png\nindex ".self::BASE.'..'.self::HEAD." 100644\n--- a/i.png\n+++ b/i.png\n@@ -9 +9 @@\n-old payload\n+new payload\n";

        $named = new BinaryFiles()->named($file, static fn (string $id): string => "\x89PNG\0\n".str_repeat("padding\n", 7).(self::BASE === $id ? 'old' : 'new')." payload\n");

        self::assertSame("diff --git a/i.png b/i.png\nindex ".self::BASE.'..'.self::HEAD." 100644\nBinary file, not shown: 74 bytes at the base, 74 bytes at the head\n", $named);
    }

    public function testATextFileKeepsItsLinesWithEachByteThatIsNotUtf8Escaped(): void
    {
        $file = "diff --git a/a.php b/a.php\nindex ".self::BASE.'..'.self::HEAD." 100644\n@@ -1 +1,2 @@\n+// caf\xe9 \u{e9}\n return true;\n";

        $named = new BinaryFiles()->named($file, static fn (string $id): string => "return true;\n");

        self::assertSame("diff --git a/a.php b/a.php\nindex ".self::BASE.'..'.self::HEAD." 100644\n@@ -1 +1,2 @@\n+// caf\u{fffd}{E9} \u{e9}\n return true;\n", $named);
    }

    public function testAReplacementCharacterOfAFileWithEscapesIsEscapedTooSoThatNoEscapeCanBeForged(): void
    {
        $file = "diff --git a/a.txt b/a.txt\nindex ".self::BASE.'..'.self::HEAD." 100644\n@@ -1 +1 @@\n-\u{fffd}{E9}\n+\xe9\n";

        $named = new BinaryFiles()->named($file, static fn (string $id): string => "text\n");

        self::assertStringContainsString("-\u{fffd}{EFBFBD}{E9}\n+\u{fffd}{E9}\n", $named);
    }

    public function testAReplacementCharacterOfAUtf8FileIsLeftAsItIs(): void
    {
        $file = "diff --git a/a.txt b/a.txt\nindex ".self::BASE.'..'.self::HEAD." 100644\n@@ -0,0 +1 @@\n+\u{fffd}\n";

        self::assertSame($file, new BinaryFiles()->named($file, static fn (string $id): string => "\u{fffd}\n"));
    }

    public function testASubmoduleIsShownWithoutReadingItsCommitsAsBlobs(): void
    {
        $file = "diff --git a/lib b/lib\nindex ".self::BASE.'..'.self::HEAD." 160000\n--- a/lib\n+++ b/lib\n@@ -1 +1 @@\n-Subproject commit ".self::BASE."\n+Subproject commit ".self::HEAD."\n";

        $named = new BinaryFiles()->named($file, static fn (string $id): string => throw new ForgeReadFailed('A submodule commit is no blob.'));

        self::assertSame($file, $named);
    }

    public function testALargeBinaryFileIsRemovedWhileTheTextFileAfterItIsKept(): void
    {
        $header = "diff --git a/a b/a\nindex ".self::BASE.'..'.self::HEAD." 100644\n";

        $named = new BinaryFiles()->named($header."+\0".str_repeat('x', 2000000)."\n".self::TEXT, static fn (string $id): string => self::HEAD === $id ? "\0".str_repeat('x', 2000000)."\n" : "old\n");

        self::assertSame($header."Binary file, not shown: 4 bytes at the base, 2000002 bytes at the head\n".self::TEXT, $named);
    }

    public function testAFileThatIsNotTextWithoutTheIdsOfItsBlobsFailsTheRead(): void
    {
        try {
            $outcome = new BinaryFiles()->named("diff --git a/b.bin b/b.bin\n+\xff\n", static fn (string $id): string => '');
        } catch (ForgeReadFailed $failure) {
            $outcome = $failure->getMessage();
        }

        self::assertStringContainsString('without the ids of its blobs', $outcome);
    }
}
