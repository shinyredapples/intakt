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
    private const string TEXT = "diff --git a/a.txt b/a.txt\nindex 1111111111111111111111111111111111111111..2222222222222222222222222222222222222222 100644\n--- a/a.txt\n+++ b/a.txt\n@@ -1 +1 @@\n-old\n+diff --git a/b.txt b/b.txt\n";

    public function testAPatchOfTextFilesIsLeftAsItIsWithoutAskingForASize(): void
    {
        $named = new BinaryFiles()->named(self::TEXT, static fn (string $blob): int => throw new ForgeReadFailed('No size is needed for text.'));

        self::assertSame(self::TEXT, $named);
    }

    public function testAFileThatIsNotTextKeepsItsHeaderAndIsNamedWithTheSizesOfBothBlobs(): void
    {
        $binary = "diff --git a/b.bin b/b.bin\nindex 1111111111111111111111111111111111111111..2222222222222222222222222222222222222222 100644\n--- a/b.bin\n+++ b/b.bin\n@@ -1 +1 @@\n-\0\n+\xff\n";

        $named = new BinaryFiles()->named(self::TEXT.$binary, static fn (string $blob): int => '1111111111111111111111111111111111111111' === $blob ? 1 : 2);

        self::assertSame(self::TEXT."diff --git a/b.bin b/b.bin\nindex 1111111111111111111111111111111111111111..2222222222222222222222222222222222222222 100644\nBinary file, not shown: 1 bytes at the base, 2 bytes at the head\n", $named);
    }

    public function testAFileThatIsNotTextWithoutTheIdsOfItsBlobsFailsTheRead(): void
    {
        try {
            $outcome = new BinaryFiles()->named("diff --git a/b.bin b/b.bin\n+\0\n", static fn (string $blob): int => 0);
        } catch (ForgeReadFailed $failure) {
            $outcome = $failure->getMessage();
        }

        self::assertStringContainsString('without the ids of its blobs', $outcome);
    }
}
