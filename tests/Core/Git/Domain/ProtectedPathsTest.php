<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\Domain;

use Intakt\Core\Git\Domain\ChangedPath;
use Intakt\Core\Git\Domain\ProtectedPathRefused;
use Intakt\Core\Git\Domain\ProtectedPaths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProtectedPaths::class)]
#[CoversClass(ProtectedPathRefused::class)]
#[UsesClass(ChangedPath::class)]
final class ProtectedPathsTest extends TestCase
{
    public function testAnEntryProtectsTheFileItNames(): void
    {
        self::assertSame(['bin/check'], $this->touched(['bin/check'], 'bin/check'));
    }

    public function testAnEntryProtectsEverythingBelowTheFolderItNames(): void
    {
        self::assertSame(['config/gates/src/a.php'], $this->touched(['config/gates'], 'config/gates/src/a.php'));
    }

    public function testATrailingSlashNamesTheSameFolder(): void
    {
        self::assertSame(['config/gates/a.php'], $this->touched(['config/gates/'], 'config/gates/a.php'));
    }

    #[DataProvider('neighbors')]
    public function testAPathThatOnlyStartsLikeAnEntryIsNotProtected(string $path): void
    {
        self::assertSame([], $this->touched(['bin/check', 'config/gates/'], $path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function neighbors(): iterable
    {
        yield 'a longer file name' => ['bin/checker'];
        yield 'a longer folder name' => ['config/gatesX/a.php'];
        yield 'a file beside the folder' => ['config/gates.php'];
        yield 'another case' => ['BIN/check'];
        yield 'the parent folder' => ['config/a.php'];
    }

    public function testTheSubmodulesFileIsProtectedWithoutAnEntry(): void
    {
        self::assertSame(['.gitmodules'], $this->touched([], '.gitmodules'));
    }

    public function testASubmodulesFileInAFolderIsNotProtectedWithoutAnEntry(): void
    {
        self::assertSame([], $this->touched([], 'lib/.gitmodules'));
    }

    public function testASubmoduleIsProtectedWithoutAnEntry(): void
    {
        $touched = new ProtectedPaths([])->touchedBy([new ChangedPath('lib', submodule: true)]);

        self::assertSame(['lib'], $touched);
    }

    public function testEachPathIsListedOnceInOrder(): void
    {
        $touched = new ProtectedPaths(['bin'])->touchedBy([
            new ChangedPath('bin/z', submodule: false),
            new ChangedPath('bin/a', submodule: false),
            new ChangedPath('bin/z', submodule: false),
        ]);

        self::assertSame(['bin/a', 'bin/z'], $touched);
    }

    #[DataProvider('malformedEntries')]
    public function testAnEntryThatNamesNoPathOfTheRepositoryIsRefused(string $entry): void
    {
        $this->expectException(ProtectedPathRefused::class);
        $this->expectExceptionMessageIsOrContains(sprintf('"%s" is not a protected path', $entry));

        new ProtectedPaths([$entry]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedEntries(): iterable
    {
        yield 'empty' => [''];
        yield 'a slash only' => ['/'];
        yield 'absolute' => ['/bin/check'];
        yield 'an empty part' => ['config//gates'];
        yield 'a dot part' => ['./bin/check'];
        yield 'a parent part' => ['config/../bin/check'];
        yield 'two trailing slashes' => ['config/gates//'];
    }

    /**
     * @param list<string> $entries
     *
     * @return list<string>
     */
    private function touched(array $entries, string $path): array
    {
        return new ProtectedPaths($entries)->touchedBy([new ChangedPath($path, submodule: false)]);
    }
}
