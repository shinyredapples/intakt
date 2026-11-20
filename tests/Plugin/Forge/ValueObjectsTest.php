<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\Forge;

use Intakt\Plugin\Forge\Contract\ChangedFile;
use Intakt\Plugin\Forge\Contract\ChangedFiles;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitIdMalformed;
use Intakt\Plugin\Forge\Contract\FileStatus;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CommitId::class)]
#[CoversClass(ChangeId::class)]
#[CoversClass(ChangedFiles::class)]
#[UsesClass(ChangedFile::class)]
#[UsesClass(FileStatus::class)]
#[UsesClass(CommitIdMalformed::class)]
final class ValueObjectsTest extends TestCase
{
    public function testAFullHashIsACommitId(): void
    {
        $id = new CommitId(str_repeat('ab', 20));

        self::assertTrue($id->equals(new CommitId(str_repeat('ab', 20))));
        self::assertFalse($id->equals(new CommitId(str_repeat('cd', 20))));
    }

    public function testAnAbbreviatedHashIsRefusedAsAMalformedCommitId(): void
    {
        $this->expectExceptionObject(new CommitIdMalformed('A commit id is a full lowercase hexadecimal hash.'));

        new CommitId('abcdef1');
    }

    public function testAHashWithATrailingNewlineIsNotACommitId(): void
    {
        $this->expectException(CommitIdMalformed::class);

        new CommitId(str_repeat('ab', 20)."\n");
    }

    public function testAnOptionLikeHashIsNotACommitId(): void
    {
        $this->expectException(CommitIdMalformed::class);

        new CommitId('--output=x');
    }

    public function testABlankChangeIdIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ChangeId(' ');
    }

    public function testChangedFilesKeepTheirOrderAndCount(): void
    {
        $first = new ChangedFile('a.php', FileStatus::Added);
        $second = new ChangedFile('b.php', FileStatus::Deleted);

        $files = new ChangedFiles($first, $second);

        self::assertCount(2, $files);
        self::assertSame([$first, $second], [...$files]);
    }
}
