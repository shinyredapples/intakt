<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\GatesCache;
use Intakt\Gates\RectorPathsFile;
use Intakt\Gates\ScopeFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(RectorPathsFile::class)]
#[UsesClass(GatesCache::class)]
#[UsesClass(ScopeFailure::class)]
final class RectorPathsFileTest extends TestCase
{
    private string $folder;

    public function testTheWrittenFileReadsBackEveryPathUnchanged(): void
    {
        $paths = ['src/A.php', "src/Line\nBreak.php", 'src/Ünïcode.php'];

        $file = RectorPathsFile::written($paths);

        self::assertSame($paths, RectorPathsFile::read($file));
    }

    public function testTheFileIsNamedAfterItsContentInTheGatesCache(): void
    {
        $file = RectorPathsFile::written(['src/A.php']);

        self::assertSame($this->folder.'/rector-paths/'.hash('xxh128', '["src/A.php"]').'.json', $file);
    }

    public function testARelativeGatesCacheIsNamedByItsAbsolutePath(): void
    {
        putenv(GatesCache::VARIABLE.'=var/rector-paths-file-test-'.getmypid());

        try {
            $file = RectorPathsFile::written(['src/A.php']);
        } finally {
            new Filesystem()->remove(getcwd().'/var/rector-paths-file-test-'.getmypid());
        }

        self::assertStringStartsWith(getcwd().'/var/rector-paths-file-test-'.getmypid().'/rector-paths/', $file);
    }

    // Because a symlink left under the final name would otherwise redirect the write to whatever file it points at.
    public function testASymlinkUnderTheFinalNameIsReplacedAndItsTargetKept(): void
    {
        $target = $this->folder.'/target.txt';
        file_put_contents($target, 'unchanged');
        new Filesystem()->mkdir($this->folder.'/rector-paths');
        symlink($target, $this->folder.'/rector-paths/'.hash('xxh128', '["src/A.php"]').'.json');

        $file = RectorPathsFile::written(['src/A.php']);

        self::assertFalse(is_link($file));
        self::assertSame(['src/A.php'], RectorPathsFile::read($file));
        self::assertSame('unchanged', file_get_contents($target));
    }

    public function testAMissingFileFailsTheRead(): void
    {
        $this->assertReadFails($this->folder.'/absent.json', 'is missing or unreadable');
    }

    public function testAnUnreadableFileFailsTheRead(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('This user ignores file permissions (superuser).');
        }

        file_put_contents($this->folder.'/locked.json', '["src/A.php"]');
        chmod($this->folder.'/locked.json', 0o000);

        $this->assertReadFails($this->folder.'/locked.json', 'is missing or unreadable');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidContents(): iterable
    {
        yield 'a partly written list' => ['["src/A.php", "src/B', 'does not decode'];
        yield 'an empty file' => ['', 'does not decode'];
        yield 'an empty list' => ['[]', 'holds no list of paths'];
        yield 'an object' => ['{"a": "src/A.php"}', 'holds no list of paths'];
        yield 'an object with list keys' => ['{"0": "src/A.php"}', 'holds no list of paths'];
        yield 'a path that is not a string' => ['["src/A.php", 1]', 'holds no list of paths'];
        yield 'an empty path' => ['["src/A.php", ""]', 'holds no list of paths'];
        yield 'a nested list' => ['[["src/A.php"]]', 'does not decode'];
    }

    #[DataProvider('invalidContents')]
    public function testContentsThatAreNoCompleteListOfPathsFailTheRead(string $contents, string $reason): void
    {
        file_put_contents($this->folder.'/paths.json', $contents);

        $this->assertReadFails($this->folder.'/paths.json', $reason);
    }

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/rector-paths-file-test-'.getmypid();
        new Filesystem()->mkdir($this->folder);
        putenv(GatesCache::VARIABLE.'='.$this->folder);
    }

    protected function tearDown(): void
    {
        putenv(GatesCache::VARIABLE);
        new Filesystem()->remove($this->folder);
    }

    private function assertReadFails(string $file, string $reason): void
    {
        try {
            RectorPathsFile::read($file);
            self::fail('The read passed although the paths file is no complete list of paths.');
        } catch (ScopeFailure $failure) {
            self::assertStringContainsString($reason, $failure->getMessage());
        }
    }
}
