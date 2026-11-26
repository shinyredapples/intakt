<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Disk\Infrastructure;

use Closure;
use Intakt\Core\Disk\Infrastructure\FreeSpaceUnreadable;
use Intakt\Core\Disk\Infrastructure\StatFreeSpace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(StatFreeSpace::class)]
#[CoversClass(FreeSpaceUnreadable::class)]
final class StatFreeSpaceTest extends TestCase
{
    public function testItReportsFreeBytesOfAnExistingFolder(): void
    {
        self::assertGreaterThan(0, new StatFreeSpace()->freeBytes(sys_get_temp_dir()));
    }

    public function testItReportsFreeInodesOfAnExistingFolder(): void
    {
        self::assertGreaterThanOrEqual(0, new StatFreeSpace()->freeInodes(sys_get_temp_dir()));
    }

    public function testAFileSystemThatKeepsNoInodeCountNeverRunsOutOfInodes(): void
    {
        self::assertSame(PHP_INT_MAX, $this->withStatPrinting('0 0', static fn (): int => new StatFreeSpace()->freeInodes(sys_get_temp_dir())));
    }

    public function testAFileSystemThatKeepsAnInodeCountReportsItsFreeInodes(): void
    {
        self::assertSame(42, $this->withStatPrinting('42 1000', static fn (): int => new StatFreeSpace()->freeInodes(sys_get_temp_dir())));
    }

    public function testStatOutputThatIsNotTwoCountsIsAnExplicitFailure(): void
    {
        $this->expectException(FreeSpaceUnreadable::class);

        $this->withStatPrinting('42', static fn (): int => new StatFreeSpace()->freeInodes(sys_get_temp_dir()));
    }

    public function testAFolderThatDoesNotExistIsAnExplicitFailureForBytes(): void
    {
        $this->expectException(FreeSpaceUnreadable::class);

        new StatFreeSpace()->freeBytes(__DIR__.'/missing');
    }

    public function testAFolderThatDoesNotExistIsAnExplicitFailureForInodes(): void
    {
        $this->expectException(FreeSpaceUnreadable::class);

        new StatFreeSpace()->freeInodes(__DIR__.'/missing');
    }

    /**
     * @param Closure(): int $read
     */
    // A stand-in stat first on the PATH, because the test machine's file system decides what the real one prints.
    private function withStatPrinting(string $output, Closure $read): int
    {
        $bin = sys_get_temp_dir().'/intakt-stat-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($bin.'/stat', "#!/bin/sh\necho '".$output."'\n");
        $filesystem->chmod($bin.'/stat', 0o755);
        $path = (string) getenv('PATH');
        putenv('PATH='.$bin.':'.$path);

        try {
            return $read();
        } finally {
            putenv('PATH='.$path);
            $filesystem->remove($bin);
        }
    }
}
