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
    public function testTheBinaryTextNamesTheByteCount(): void
    {
        self::assertSame('Binary file, not shown: 29 bytes', FileContent::binaryText(29));
    }

    public function testABinaryFileCarriesTheBinaryTextAndItsSize(): void
    {
        $file = FileContent::binary('logo.png', new CommitId(str_repeat('a', 40)), 29);

        self::assertSame(FileContent::binaryText(29), $file->content);
        self::assertSame(29, $file->binaryBytes);
    }
}
