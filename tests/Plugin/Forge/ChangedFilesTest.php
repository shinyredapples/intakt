<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\Forge;

use Intakt\Plugin\Forge\Contract\ChangedFile;
use Intakt\Plugin\Forge\Contract\ChangedFiles;
use Intakt\Plugin\Forge\Contract\FileStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangedFiles::class)]
#[UsesClass(ChangedFile::class)]
#[UsesClass(FileStatus::class)]
final class ChangedFilesTest extends TestCase
{
    public function testChangedFilesKeepTheirOrderAndCount(): void
    {
        $first = new ChangedFile('a.php', FileStatus::Added);
        $second = new ChangedFile('b.php', FileStatus::Deleted);

        $files = new ChangedFiles($first, $second);

        self::assertCount(2, $files);
        self::assertSame([$first, $second], [...$files]);
    }
}
