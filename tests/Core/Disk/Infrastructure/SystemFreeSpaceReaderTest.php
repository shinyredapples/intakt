<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Disk\Infrastructure;

use Intakt\Core\Disk\Contract\FreeSpaceUnreadable;
use Intakt\Core\Disk\Infrastructure\SystemFreeSpaceReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SystemFreeSpaceReader::class)]
#[UsesClass(FreeSpaceUnreadable::class)]
final class SystemFreeSpaceReaderTest extends TestCase
{
    public function testAnExistingFolderReportsItsFreeBytes(): void
    {
        $free = new SystemFreeSpaceReader()->freeBytes(__DIR__);

        self::assertGreaterThan(0, $free);
    }

    public function testAnExistingFolderReportsItsFreeInodesOrNoInodeCount(): void
    {
        $free = new SystemFreeSpaceReader()->freeInodes(__DIR__);

        if (null === $free) {
            self::markTestSkipped('This file system reports no inode count.');
        }

        self::assertGreaterThanOrEqual(0, $free);
    }

    public function testTheFreeBytesOfAFolderThatDoesNotExistAreUnreadable(): void
    {
        $this->expectException(FreeSpaceUnreadable::class);

        new SystemFreeSpaceReader()->freeBytes($this->missingFolder());
    }

    public function testTheFreeInodesOfAFolderThatDoesNotExistAreUnreadable(): void
    {
        $this->expectException(FreeSpaceUnreadable::class);

        new SystemFreeSpaceReader()->freeInodes($this->missingFolder());
    }

    public function testAnInodeReadThatIsStoppedByASignalIsUnreadable(): void
    {
        $folder = sys_get_temp_dir().'/disk-signal-'.bin2hex(random_bytes(8));
        mkdir($folder);
        file_put_contents($folder.'/df', "#!/bin/sh\nkill -TERM \"\$\$\"\n");
        chmod($folder.'/df', 0700);
        $path = (string) getenv('PATH');
        putenv('PATH='.$folder);

        try {
            $this->expectException(FreeSpaceUnreadable::class);
            new SystemFreeSpaceReader()->freeInodes($folder);
        } finally {
            putenv('PATH='.$path);
            unlink($folder.'/df');
            rmdir($folder);
        }
    }

    public function testAnInodeReportWithFreeInodesIsReadFromItsSecondLine(): void
    {
        self::assertSame(3387666, SystemFreeSpaceReader::freeInodesOf("  Inodes   IFree\n8912896 3387666\n", '/data'));
    }

    public function testAnInodeReportWithoutAnInodeCountMeansNoInodeLimit(): void
    {
        self::assertNull(SystemFreeSpaceReader::freeInodesOf("Inodes IFree\n     0     0\n", '/data'));
    }

    public function testAnInodeReportThatIsNotTwoNumbersIsUnreadable(): void
    {
        $this->expectExceptionObject(new FreeSpaceUnreadable('/data', 'df printed no inode counts'));

        SystemFreeSpaceReader::freeInodesOf("Inodes IFree\n- -\n", '/data');
    }

    public function testAnEmptyInodeReportIsUnreadable(): void
    {
        $this->expectException(FreeSpaceUnreadable::class);

        SystemFreeSpaceReader::freeInodesOf('', '/data');
    }

    private function missingFolder(): string
    {
        return __DIR__.'/no-such-folder';
    }
}
