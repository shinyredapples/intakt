<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\Forge;

use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\FileContent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileContent::class)]
#[UsesClass(CommitId::class)]
final class FileContentTest extends TestCase
{
    public function testABinaryFileOfOneByteIsNamedInTheSingular(): void
    {
        $file = FileContent::binary('logo.png', $this->commit(), 1);

        self::assertSame('Binary file, not shown: 1 byte', $file->content);
        self::assertSame(1, $file->binaryBytes);
    }

    public function testABinaryFileOfSeveralBytesIsNamedInThePlural(): void
    {
        $file = FileContent::binary('logo.png', $this->commit(), 29);

        self::assertSame('Binary file, not shown: 29 bytes', $file->content);
    }

    public function testAnEmptyBinaryFileIsNamedInThePlural(): void
    {
        $file = FileContent::binary('logo.png', $this->commit(), 0);

        self::assertSame('Binary file, not shown: 0 bytes', $file->content);
    }

    private function commit(): CommitId
    {
        return new CommitId(str_repeat('ab', 20));
    }
}
