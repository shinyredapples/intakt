<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Disk\Contract;

use Intakt\Core\Disk\Contract\NearestFolder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(NearestFolder::class)]
final class NearestFolderTest extends TestCase
{
    private string $scratch;

    public function testAFolderThatExistsIsItsOwnNearestFolder(): void
    {
        self::assertSame($this->scratch, NearestFolder::of($this->scratch));
    }

    public function testAFolderThatDoesNotExistYetLiesInTheNearestFolderAboveItThatDoes(): void
    {
        self::assertSame($this->scratch, NearestFolder::of($this->scratch.'/clones/42'));
    }

    public function testAFileOnThePathIsSkippedForTheFolderThatHoldsIt(): void
    {
        new Filesystem()->touch($this->scratch.'/file');

        self::assertSame($this->scratch, NearestFolder::of($this->scratch.'/file/42'));
    }

    public function testTheSearchStopsAtTheRootOfARelativePath(): void
    {
        self::assertSame('.', NearestFolder::of('intakt-no-such-folder-'.bin2hex(random_bytes(6)).'/42'));
    }

    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir().'/intakt-nearest-folder-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->scratch);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->scratch);
    }
}
