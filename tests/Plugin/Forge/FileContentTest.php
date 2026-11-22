<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\Forge;

use Intakt\Plugin\Forge\Contract\BinaryFiles;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\FileContent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileContent::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(BinaryFiles::class)]
final class FileContentTest extends TestCase
{
    public function testTheBinaryTextNamesTheByteCount(): void
    {
        self::assertSame('Binary file, not shown: 29 bytes', FileContent::binaryText(29));
    }

    public function testABinaryFileCarriesTheBinaryTextAndItsSize(): void
    {
        $file = FileContent::binary('logo.png', $this->commit(), 29);

        self::assertSame(FileContent::binaryText(29), $file->content);
        self::assertSame(29, $file->binaryBytes);
    }

    public function testBytesWithANulByteAnywhereAreNamedBinaryByTheirSize(): void
    {
        $file = FileContent::ofBytes('logo.png', $this->commit(), "text\0");

        self::assertSame(5, $file->binaryBytes);
        self::assertSame('Binary file, not shown: 5 bytes', $file->content);
    }

    public function testBytesWithoutANulByteAreKeptAsTheyAre(): void
    {
        $file = FileContent::ofBytes('notes.txt', $this->commit(), "caf\xe9\n");

        self::assertNull($file->binaryBytes);
        self::assertSame("caf\xe9\n", $file->content);
    }

    private function commit(): CommitId
    {
        return new CommitId(str_repeat('a', 40));
    }
}
