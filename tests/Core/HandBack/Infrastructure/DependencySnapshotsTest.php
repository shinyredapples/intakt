<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\Infrastructure;

use FilesystemIterator;
use Intakt\Core\HandBack\Domain\DependencySnapshotKey;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshotCountRefused;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshots;
use Intakt\Core\Job\Contract\JobInput;
use Intakt\Plugin\Gate\Contract\DependencyStep;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(DependencySnapshots::class)]
#[CoversClass(DependencySnapshotCountRefused::class)]
#[UsesClass(DependencySnapshotKey::class)]
#[UsesClass(DependencyStep::class)]
#[UsesClass(JobInput::class)]
final class DependencySnapshotsTest extends TestCase
{
    private string $directory;

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-dependency-snapshots-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->directory);
        $this->clock = new MockClock('2026-11-19T12:00:00Z');
    }

    protected function tearDown(): void
    {
        new Filesystem()->chmod($this->directory, 0o755, 0o000, true);
        new Filesystem()->remove($this->directory);
    }

    public function testASavedSnapshotHasNoWriteBitForAnyoneAnywhereInItsTree(): void
    {
        $this->store()->save($this->key('a'), $this->install('a'));

        foreach ([$this->snapshotOf('a'), ...$this->pathsUnder($this->snapshotOf('a'))] as $path) {
            self::assertSame(0, fileperms($path) & 0o222, $path);
        }
        self::assertSame(0o555, fileperms($this->snapshotOf('a').'/vendor/bin/tool') & 0o777);
        self::assertSame(0o444, fileperms($this->snapshotOf('a').'/vendor/fixture/pkg/code.php') & 0o777);
    }

    public function testAJobUserCanNeitherChangeNorAddAFileInASavedSnapshot(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('root ignores file modes');
        }
        $this->store()->save($this->key('a'), $this->install('a'));

        self::assertFalse(@file_put_contents($this->snapshotOf('a').'/vendor/fixture/pkg/code.php', 'planted'));
        self::assertFalse(@file_put_contents($this->snapshotOf('a').'/vendor/fixture/pkg/planted.php', 'planted'));
        self::assertFalse(@mkdir($this->snapshotOf('a').'/vendor/planted'));
        self::assertStringEqualsFile($this->snapshotOf('a').'/vendor/fixture/pkg/code.php', '<?php // a');
    }

    public function testAJobReachesASnapshotOnlyAsAReadOnlyMountOfItsFolder(): void
    {
        $this->store()->save($this->key('a'), $this->install('a'));

        $mount = $this->store()->mountOf($this->key('a'), '/input/dependencies');

        self::assertEquals(new JobInput($this->snapshotOf('a'), '/input/dependencies'), $mount);
    }

    public function testAKeyWithoutASnapshotHasNoMount(): void
    {
        self::assertNull($this->store()->mountOf($this->key('absent'), '/input/dependencies'));
    }

    public function testOnlyTheStoresOwnerMayAddToTheStore(): void
    {
        $this->store()->save($this->key('a'), $this->install('a'));

        self::assertSame(0o755, fileperms($this->directory.'/store') & 0o777);
    }

    public function testASnapshotKeepsALinkAsALinkAndLeavesWhatItPointsAtWritable(): void
    {
        $install = $this->install('a');
        new Filesystem()->dumpFile($this->directory.'/outside', 'outside');
        symlink($this->directory.'/outside', $install.'/vendor/link');

        $this->store()->save($this->key('a'), $install);

        self::assertTrue(is_link($this->snapshotOf('a').'/vendor/link'));
        self::assertSame(0o200, fileperms($this->directory.'/outside') & 0o200);
    }

    public function testSavingAnAlreadyStoredKeyKeepsTheFirstSnapshot(): void
    {
        $this->store()->save($this->key('a'), $this->install('a'));

        $this->store()->save($this->key('a'), $this->install('b'));

        self::assertStringEqualsFile($this->snapshotOf('a').'/vendor/fixture/pkg/code.php', '<?php // a');
    }

    public function testSavingTheEleventhSnapshotWithTheDefaultCountEvictsTheLeastRecentlyUsedOne(): void
    {
        foreach (range(1, 11) as $number) {
            $this->store()->save($this->key((string) $number), $this->install((string) $number));
            $this->clock->sleep(60);
        }

        self::assertDirectoryDoesNotExist($this->snapshotOf('1'));
        self::assertCount(10, $this->snapshots());
        self::assertDirectoryExists($this->snapshotOf('11'));
    }

    public function testTenSnapshotsWithTheDefaultCountAreAllKept(): void
    {
        foreach (range(1, 10) as $number) {
            $this->store()->save($this->key((string) $number), $this->install((string) $number));
            $this->clock->sleep(60);
        }

        self::assertCount(10, $this->snapshots());
    }

    public function testASnapshotMountedAgainIsKeptWhileAnOlderUnusedOneIsEvicted(): void
    {
        $store = $this->store(2);
        $store->save($this->key('first'), $this->install('first'));
        $this->clock->sleep(60);
        $store->save($this->key('second'), $this->install('second'));
        $this->clock->sleep(60);
        self::assertNotNull($store->mountOf($this->key('first'), '/input/dependencies'));
        $this->clock->sleep(60);

        $store->save($this->key('third'), $this->install('third'));

        self::assertDirectoryExists($this->snapshotOf('first'));
        self::assertDirectoryDoesNotExist($this->snapshotOf('second'));
        self::assertDirectoryExists($this->snapshotOf('third'));
    }

    public function testACountOfOneKeepsOnlyTheSnapshotJustSaved(): void
    {
        $store = $this->store(1);
        $store->save($this->key('first'), $this->install('first'));
        $this->clock->sleep(60);

        $store->save($this->key('second'), $this->install('second'));

        self::assertSame([$this->key('second')->value], $this->snapshots());
    }

    public function testACountBelowOneIsRefused(): void
    {
        $this->expectException(DependencySnapshotCountRefused::class);

        $this->store(0);
    }

    public function testACopyLeftByACrashedSaveIsRemovedByTheNextSave(): void
    {
        new Filesystem()->dumpFile($this->directory.'/store/.incoming/vendor/half.php', 'half');
        chmod($this->directory.'/store/.incoming/vendor', 0o555);

        $this->store()->save($this->key('a'), $this->install('a'));

        self::assertDirectoryDoesNotExist($this->directory.'/store/.incoming');
        self::assertSame([$this->key('a')->value], $this->snapshots());
    }

    private function store(int $count = 10): DependencySnapshots
    {
        return new DependencySnapshots($this->directory.'/store', $count, $this->clock, new LockFactory(new InMemoryStore()));
    }

    private function key(string $lock): DependencySnapshotKey
    {
        $step = new DependencyStep(['composer.json', 'composer.lock'], 'probe', 'install', 'generate');

        return DependencySnapshotKey::of($step, ['composer.json' => '{}', 'composer.lock' => $lock], '8.5.12');
    }

    private function snapshotOf(string $lock): string
    {
        return $this->directory.'/store/'.$this->key($lock)->value;
    }

    private function install(string $name): string
    {
        $install = $this->directory.'/install-'.$name.'-'.bin2hex(random_bytes(3));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($install.'/vendor/fixture/pkg/code.php', '<?php // '.$name);
        $filesystem->dumpFile($install.'/vendor/bin/tool', '#!/bin/sh');
        $filesystem->chmod($install.'/vendor/bin/tool', 0o755);

        return $install;
    }

    /**
     * @return list<string>
     */
    private function snapshots(): array
    {
        $names = array_values(array_filter((array) scandir($this->directory.'/store'), static fn (mixed $name): bool => is_string($name) && DependencySnapshotKey::isValue($name)));
        sort($names);

        return array_map(strval(...), $names);
    }

    /**
     * @return list<string>
     */
    private function pathsUnder(string $directory): array
    {
        $paths = [];
        /** @var SplFileInfo $entry */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            $paths[] = $entry->getPathname();
        }

        return $paths;
    }
}
