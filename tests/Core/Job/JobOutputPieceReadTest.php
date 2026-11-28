<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Intakt\Core\Job\Contract\JobOutputPiece;
use Intakt\Core\Job\Infrastructure\JobOutputDirectories;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

// The running job owns the folder, so each test puts there what a job could leave in place of the followed file.
#[CoversClass(JobOutputDirectories::class)]
#[UsesClass(JobOutputPiece::class)]
final class JobOutputPieceReadTest extends TestCase
{
    private string $folder = '';

    public function testAPieceHoldsNoMoreThanTheAskedBytesFromTheAskedOffset(): void
    {
        file_put_contents($this->folder.'/build.log', 'abcdefgh');

        $piece = $this->piece(2, 3);

        self::assertSame(['cde', 8], [$piece?->bytes, $piece?->size]);
    }

    public function testAPieceFromTheEndOrBeyondIsEmptyAndTellsTheSize(): void
    {
        file_put_contents($this->folder.'/build.log', 'abc');

        self::assertSame(['', 3], [$this->piece(3, 10)?->bytes, $this->piece(3, 10)?->size]);
        self::assertSame(['', 3], [$this->piece(9, 10)?->bytes, $this->piece(9, 10)?->size]);
    }

    public function testAFileWrittenAgainInItsPlaceHasAnotherIdentity(): void
    {
        file_put_contents($this->folder.'/build.log', 'abc');
        $first = $this->piece(0, 10)?->identity;
        file_put_contents($this->folder.'/other', 'abc');
        rename($this->folder.'/other', $this->folder.'/build.log');

        self::assertNotSame($first, $this->piece(0, 10)?->identity);
    }

    public function testAMissingFileGivesNoPiece(): void
    {
        self::assertNull($this->piece(0, 10));
    }

    public function testALinkInPlaceOfTheFileIsNeverFollowed(): void
    {
        file_put_contents($this->folder.'/target', 'secret');
        symlink($this->folder.'/target', $this->folder.'/build.log');

        self::assertNull($this->piece(0, 10));
    }

    public function testAPipeInPlaceOfTheFileGivesNoPieceAndNeverWaitsForAWriter(): void
    {
        posix_mkfifo($this->folder.'/build.log', 0o600);

        self::assertNull($this->piece(0, 10));
    }

    public function testAFolderInPlaceOfTheFileGivesNoPiece(): void
    {
        mkdir($this->folder.'/build.log');

        self::assertNull($this->piece(0, 10));
    }

    public function testAFileTheOrchestratorCannotOpenGivesNoPiece(): void
    {
        file_put_contents($this->folder.'/build.log', 'abc');
        chmod($this->folder.'/build.log', 0o400);

        self::assertNull($this->piece(0, 10));
    }

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/intakt-output-piece-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->folder);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->folder);
    }

    private function piece(int $from, int $maxBytes): ?JobOutputPiece
    {
        return new JobOutputDirectories($this->folder)->piece($this->folder.'/build.log', $from, $maxBytes);
    }
}
