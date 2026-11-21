<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\Infrastructure;

use FilesystemIterator;
use Intakt\Core\HandBack\Domain\DependencySnapshotKey;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshotCountRefused;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshotFiles;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshotLease;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshots;
use Intakt\Core\Job\Contract\JobEgress;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobInput;
use Intakt\Core\Job\Contract\JobLimits;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Core\Job\Infrastructure\EgressRoute;
use Intakt\Core\Job\Infrastructure\JobContainerDefinition;
use Intakt\Core\Job\Infrastructure\JobContainerRequest;
use Intakt\Plugin\Gate\Contract\DependencyStep;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Process\Process;

#[CoversClass(DependencySnapshots::class)]
#[CoversClass(DependencySnapshotFiles::class)]
#[CoversClass(DependencySnapshotLease::class)]
#[CoversClass(DependencySnapshotCountRefused::class)]
#[UsesClass(DependencySnapshotKey::class)]
#[UsesClass(DependencyStep::class)]
#[UsesClass(JobInput::class)]
#[UsesClass(JobContainerDefinition::class)]
#[UsesClass(JobContainerRequest::class)]
#[UsesClass(JobSpec::class)]
#[UsesClass(JobId::class)]
#[UsesClass(JobLimits::class)]
#[UsesClass(JobEgress::class)]
#[UsesClass(EgressRoute::class)]
final class DependencySnapshotsTest extends TestCase
{
    private const string START_CHECK = __DIR__.'/../../../../docker/job/start-check.sh';

    // Run in a process of its own, so the stand-in for Filesystem's iterator_to_array() is defined before Filesystem is first called, and the save it stops never runs its cleanup, as a killed orchestrator would not. It stops the first delete of a folder Filesystem::remove() moved aside.
    private const string INTERRUPTED_EVICTION = <<<'PHP'
        namespace Symfony\Component\Filesystem {
            function iterator_to_array(\Traversable $iterator, bool $preserve_keys = true): array
            {
                if ($iterator instanceof \FilesystemIterator && str_ends_with($iterator->getPath(), '/d')) {
                    fwrite(STDERR, 'eviction interrupted');
                    exit(1);
                }

                return \iterator_to_array($iterator, $preserve_keys);
            }
        }

        namespace {
            use Intakt\Core\HandBack\Domain\DependencySnapshotKey;
            use Intakt\Core\HandBack\Infrastructure\DependencySnapshots;
            use Intakt\Plugin\Gate\Contract\DependencyStep;
            use Symfony\Component\Lock\LockFactory;
            use Symfony\Component\Lock\Store\FlockStore;

            require $argv[1].'/vendor/autoload.php';
            $store = new DependencySnapshots($argv[2].'/store', 1, new LockFactory(new FlockStore($argv[2].'/locks')));
            $step = new DependencyStep(['composer.json', 'composer.lock'], 'probe', 'install', 'generate');
            $store->save(DependencySnapshotKey::of($step, ['composer.json' => '{}', 'composer.lock' => 'b'], '8.5.12'), $argv[3]);
        }
        PHP;

    // Run in a process of its own, so the stand-ins for the store's rename() and chmod() are defined before the store first calls them. The failing call is named by its first argument: a publication or an eviction rename, or the chmod of the install's tool.
    private const string FAILING_CALL = <<<'PHP'
        namespace Intakt\Core\HandBack\Infrastructure {
            function rename(string $from, string $to): bool
            {
                $eviction = str_starts_with(basename($to), '.evicting-');

                if (('publication' === $GLOBALS['argv'][4] && !$eviction) || ('eviction' === $GLOBALS['argv'][4] && $eviction)) {
                    fwrite(STDERR, 'rename refused;');

                    return false;
                }

                return \rename($from, $to);
            }

            function chmod(string $path, int $mode): bool
            {
                if ('chmod' === $GLOBALS['argv'][4] && str_ends_with($path, '/vendor/bin/tool')) {
                    fwrite(STDERR, 'chmod refused;');

                    return false;
                }

                return \chmod($path, $mode);
            }
        }

        namespace {
            use Intakt\Core\HandBack\Domain\DependencySnapshotKey;
            use Intakt\Core\HandBack\Infrastructure\DependencySnapshots;
            use Intakt\Plugin\Gate\Contract\DependencyStep;
            use Symfony\Component\Filesystem\Exception\IOException;
            use Symfony\Component\Lock\LockFactory;
            use Symfony\Component\Lock\Store\FlockStore;

            require $argv[1].'/vendor/autoload.php';
            $store = new DependencySnapshots($argv[2].'/store', 1, new LockFactory(new FlockStore($argv[2].'/locks')));
            $step = new DependencyStep(['composer.json', 'composer.lock'], 'probe', 'install', 'generate');

            try {
                $store->save(DependencySnapshotKey::of($step, ['composer.json' => '{}', 'composer.lock' => 'b'], '8.5.12'), $argv[3]);
            } catch (IOException) {
                fwrite(STDERR, 'save failed;');
                exit(3);
            }
        }
        PHP;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-dependency-snapshots-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir([$this->directory, $this->directory.'/locks']);
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

    public function testACheckJobGetsALeasedSnapshotAsAReadOnlyBindOfItsOwnFolderAndNeverTheStore(): void
    {
        $this->store()->save($this->key('a'), $this->install('a'));
        $lease = $this->store()->leaseOf($this->key('a'), '/input/dependencies');
        self::assertNotNull($lease);

        $spec = new JobSpec(new JobId('check-1'), 'sample:latest', ['true'], [$lease->input], new JobLimits(1 << 30, 1.0, 60), JobEgress::none());
        $hostConfig = new JobContainerDefinition(self::START_CHECK)->of($spec, $this->directory.'/output', EgressRoute::none())->definition['HostConfig'];

        self::assertIsArray($hostConfig);
        $mounts = $hostConfig['Mounts'];
        self::assertIsArray($mounts);
        self::assertContains(['Type' => 'bind', 'Source' => $this->snapshotOf('a'), 'Target' => '/input/dependencies', 'ReadOnly' => true], $mounts);
        self::assertNotContains($this->directory.'/store', array_column($mounts, 'Source'));
    }

    public function testAKeyWithoutASnapshotHasNoLease(): void
    {
        self::assertNull($this->store()->leaseOf($this->key('absent'), '/input/dependencies'));
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
        }

        self::assertDirectoryDoesNotExist($this->snapshotOf('1'));
        self::assertCount(10, $this->snapshots());
        self::assertDirectoryExists($this->snapshotOf('11'));
    }

    public function testTenSnapshotsWithTheDefaultCountAreAllKept(): void
    {
        foreach (range(1, 10) as $number) {
            $this->store()->save($this->key((string) $number), $this->install((string) $number));
        }

        self::assertCount(10, $this->snapshots());
    }

    public function testASnapshotLeasedAgainIsKeptWhileAnOlderUnusedOneIsEvicted(): void
    {
        $store = $this->store(2);
        $store->save($this->key('first'), $this->install('first'));
        $store->save($this->key('second'), $this->install('second'));
        $store->leaseOf($this->key('first'), '/input/dependencies')?->release();

        $store->save($this->key('third'), $this->install('third'));

        self::assertDirectoryExists($this->snapshotOf('first'));
        self::assertDirectoryDoesNotExist($this->snapshotOf('second'));
        self::assertDirectoryExists($this->snapshotOf('third'));
    }

    public function testTheSnapshotJustSavedIsKeptWhenItsKeySortsBeforeTheOlderOnes(): void
    {
        $store = $this->store(1);
        [$earlier, $later] = $this->keysInHashOrder('x', 'y');
        $store->save($later, $this->install('later'));

        $store->save($earlier, $this->install('earlier'));

        self::assertSame([$earlier->value], $this->snapshots());
    }

    public function testASnapshotLeasedByAnotherProcessSurvivesASaveThatWouldEvictItUntilTheLeaseEnds(): void
    {
        $reader = $this->store(1);
        $writer = $this->store(1);
        $reader->save($this->key('first'), $this->install('first'));
        $lease = $reader->leaseOf($this->key('first'), '/input/dependencies');
        self::assertNotNull($lease);

        $writer->save($this->key('second'), $this->install('second'));
        self::assertFileExists($lease->input->hostPath.'/vendor/fixture/pkg/code.php');

        $lease->release();
        $writer->save($this->key('third'), $this->install('third'));
        self::assertSame([$this->key('third')->value], $this->snapshots());
    }

    public function testWhileTheLeastRecentlyUsedSnapshotIsLeasedTheNextOneIsEvictedSoTheStoreStaysBounded(): void
    {
        $store = $this->store(2);
        $store->save($this->key('first'), $this->install('first'));
        $lease = $store->leaseOf($this->key('first'), '/input/dependencies');
        $store->save($this->key('second'), $this->install('second'));

        $store->save($this->key('third'), $this->install('third'));

        self::assertNotNull($lease);
        self::assertEqualsCanonicalizing([$this->key('first')->value, $this->key('third')->value], $this->snapshots());
    }

    public function testACountOfOneKeepsOnlyTheSnapshotJustSaved(): void
    {
        $store = $this->store(1);
        $store->save($this->key('first'), $this->install('first'));

        $store->save($this->key('second'), $this->install('second'));

        self::assertSame([$this->key('second')->value], $this->snapshots());
    }

    public function testAHitAfterTheLeasesEndTrimsTheStoreBackToTheCount(): void
    {
        $store = $this->store(1);
        $store->save($this->key('a'), $this->install('a'));
        $lease = $store->leaseOf($this->key('a'), '/input/dependencies');
        $store->save($this->key('b'), $this->install('b'));
        self::assertCount(2, $this->snapshots());
        $lease?->release();

        $this->store(1)->leaseOf($this->key('b'), '/input/dependencies')?->release();

        self::assertSame([$this->key('b')->value], $this->snapshots());
    }

    public function testALeaseNeverEvictsTheSnapshotItLeases(): void
    {
        $store = $this->store(1);
        $store->save($this->key('a'), $this->install('a'));
        $lease = $store->leaseOf($this->key('a'), '/input/dependencies');
        $store->save($this->key('b'), $this->install('b'));
        $lease?->release();

        $again = $this->store(1)->leaseOf($this->key('a'), '/input/dependencies');

        self::assertNotNull($again);
        self::assertSame([$this->key('a')->value], $this->snapshots());
        self::assertFileExists($again->input->hostPath.'/vendor/fixture/pkg/code.php');
    }

    public function testSixteenSavesOfDistinctKeysAtACountOfOneLeaveAtMostTwoPinFiles(): void
    {
        $store = $this->store(1);

        foreach (range(1, 16) as $number) {
            $store->save($this->key((string) $number), $this->install((string) $number));
            $store->leaseOf($this->key((string) $number), '/input/dependencies')?->release();
        }

        self::assertLessThanOrEqual(2, count($this->pinFiles()));
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

    public function testPinFoldersAnInterruptedEvictionLeftBehindAreGoneAfterTheNextSave(): void
    {
        $this->plantOrphanPins('x', 'y', 'z');

        $this->store(1)->save($this->key('a'), $this->install('a'));

        self::assertSame([], array_intersect($this->pinFolders(), [$this->key('x')->value, $this->key('y')->value, $this->key('z')->value]));
        self::assertLessThanOrEqual(2, count($this->pinFolders()));
    }

    public function testPinFoldersAnInterruptedEvictionLeftBehindAreGoneAfterTheNextLease(): void
    {
        $store = $this->store(1);
        $store->save($this->key('a'), $this->install('a'));
        $this->plantOrphanPins('x', 'y', 'z');

        $store->leaseOf($this->key('a'), '/input/dependencies')?->release();

        self::assertSame([$this->key('a')->value], $this->pinFolders());
    }

    public function testAnOrphanPinThatIsBusyIsSkippedAndRemovedOnceItIsFree(): void
    {
        $this->plantOrphanPins('x');
        $holder = new LockFactory(new FlockStore($this->directory.'/store/.pins/'.$this->key('x')->value))->createLock('pin', null);
        self::assertTrue($holder->acquireRead(false));

        $this->store(1)->save($this->key('a'), $this->install('a'));
        self::assertContains($this->key('x')->value, $this->pinFolders());

        $holder->release();
        $this->store(1)->save($this->key('b'), $this->install('b'));
        self::assertNotContains($this->key('x')->value, $this->pinFolders());
    }

    public function testAPinWhoseSnapshotIsStoredIsNotSwept(): void
    {
        $store = $this->store(2);
        $store->save($this->key('a'), $this->install('a'));
        $store->leaseOf($this->key('a'), '/input/dependencies')?->release();

        $store->save($this->key('b'), $this->install('b'));

        self::assertContains($this->key('a')->value, $this->pinFolders());
    }

    public function testAHalfRemovedSnapshotIsNeverLeasedAndTheLeaseRemovesIt(): void
    {
        $this->store()->save($this->key('a'), $this->install('a'));
        $this->halfRemove('a');

        self::assertNull($this->store()->leaseOf($this->key('a'), '/input/dependencies'));
        self::assertDirectoryDoesNotExist($this->evictingOf('a'));
        self::assertSame([], $this->snapshots());
    }

    public function testAHalfRemovedSnapshotIsRemovedByTheNextSave(): void
    {
        $this->store()->save($this->key('a'), $this->install('a'));
        $this->halfRemove('a');

        $this->store()->save($this->key('b'), $this->install('b'));

        self::assertDirectoryDoesNotExist($this->evictingOf('a'));
        self::assertSame([$this->key('b')->value], $this->snapshots());
    }

    public function testAnEvictionStoppedWhileDeletingLeavesNothingTheNextLeaseDoesNotRemove(): void
    {
        $this->store(1)->save($this->key('a'), $this->install('a'));

        $interrupted = new Process([PHP_BINARY, '-r', self::INTERRUPTED_EVICTION, dirname(__DIR__, 4), $this->directory, $this->install('b')]);
        $interrupted->run();
        self::assertStringContainsString('eviction interrupted', $interrupted->getErrorOutput().$interrupted->getOutput());
        self::assertSame(1, $interrupted->getExitCode());

        self::assertNull($this->store(1)->leaseOf($this->key('a'), '/input/dependencies'));
        self::assertSame(['.pins', '.uses', $this->key('b')->value], $this->entriesOfStore());
    }

    public function testAPublicationWhoseRenameFailsLeavesOnlyTheIncomingCopyWhichTheNextSaveRemoves(): void
    {
        self::assertSame('rename refused;save failed;', $this->saveOfBWhereThisFails('publication'));
        self::assertSame([], $this->snapshots());
        self::assertDirectoryExists($this->directory.'/store/.incoming');

        $this->store(1)->save($this->key('c'), $this->install('c'));

        self::assertSame(['.uses', $this->key('c')->value], $this->entriesOfStore());
    }

    public function testAnEvictionWhoseRenameFailsKeepsTheSnapshotWholeUnderItsKeyWithItsPinFolder(): void
    {
        $this->store(1)->save($this->key('a'), $this->install('a'));

        self::assertSame('rename refused;save failed;', $this->saveOfBWhereThisFails('eviction'));

        self::assertEqualsCanonicalizing([$this->key('a')->value, $this->key('b')->value], $this->snapshots());
        self::assertStringEqualsFile($this->snapshotOf('a').'/vendor/fixture/pkg/code.php', '<?php // a');
        self::assertFileExists($this->snapshotOf('a').'/vendor/bin/tool');
        self::assertContains($this->key('a')->value, $this->pinFolders());
        self::assertSame([], preg_grep('/^\.evicting-/', $this->entriesOfStore()));
    }

    public function testASaveWhoseChmodFailsOnOneEntryThrowsAndLeavesNoFolderUnderTheKey(): void
    {
        self::assertSame('chmod refused;save failed;', $this->saveOfBWhereThisFails('chmod'));

        self::assertDirectoryDoesNotExist($this->snapshotOf('b'));
        self::assertSame([], $this->snapshots());
    }

    public function testAnInterruptedWriteOfTheOrderOfUseIsGoneAfterTheNextSave(): void
    {
        $this->store()->save($this->key('a'), $this->install('a'));
        new Filesystem()->dumpFile($this->directory.'/store/.usesAb12Cd', '["half');

        $this->store()->save($this->key('b'), $this->install('b'));

        self::assertSame(['.uses', ...$this->snapshots()], $this->entriesOfStore());
    }

    public function testAnInterruptedWriteOfTheOrderOfUseIsGoneAfterTheNextLease(): void
    {
        $this->store()->save($this->key('a'), $this->install('a'));
        new Filesystem()->dumpFile($this->directory.'/store/.usesAb12Cd', '["half');

        $this->store()->leaseOf($this->key('a'), '/input/dependencies')?->release();

        self::assertSame(['.pins', '.uses', $this->key('a')->value], $this->entriesOfStore());
    }

    public function testAnEvictedSnapshotLeavesNoFolderBehindUnderAnyName(): void
    {
        $store = $this->store(1);
        $store->save($this->key('a'), $this->install('a'));

        $store->save($this->key('b'), $this->install('b'));

        self::assertSame(['.pins', '.uses', $this->key('b')->value], $this->entriesOfStore());
    }

    // File locks, as the application's default lock store uses, and a factory of its own per store, as two orchestrator processes would have.
    private function store(int $count = 10): DependencySnapshots
    {
        return new DependencySnapshots($this->directory.'/store', $count, new LockFactory(new FlockStore($this->directory.'/locks')));
    }

    // The error output is returned whole, because it is the only proof that the stand-in refused the call before the save failed.
    private function saveOfBWhereThisFails(string $call): string
    {
        $process = new Process([PHP_BINARY, '-r', self::FAILING_CALL, dirname(__DIR__, 4), $this->directory, $this->install('b'), $call]);
        $process->run();
        self::assertSame(3, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());

        return $process->getErrorOutput();
    }

    private function key(string $lock): DependencySnapshotKey
    {
        $step = new DependencyStep(['composer.json', 'composer.lock'], 'probe', 'install', 'generate');

        return DependencySnapshotKey::of($step, ['composer.json' => '{}', 'composer.lock' => $lock], '8.5.12');
    }

    /**
     * @return array{DependencySnapshotKey, DependencySnapshotKey}
     */
    private function keysInHashOrder(string $one, string $other): array
    {
        $keys = [$this->key($one), $this->key($other)];
        usort($keys, static fn (DependencySnapshotKey $a, DependencySnapshotKey $b): int => strcmp($a->value, $b->value));

        return [$keys[0], $keys[1]];
    }

    private function snapshotOf(string $lock): string
    {
        return $this->directory.'/store/'.$this->key($lock)->value;
    }

    private function evictingOf(string $lock): string
    {
        return $this->directory.'/store/.evicting-'.$this->key($lock)->value;
    }

    // Forced on disk, because no test can stop a real eviction between its rename and the end of its delete.
    private function halfRemove(string $lock): void
    {
        rename($this->snapshotOf($lock), $this->evictingOf($lock));
        chmod($this->evictingOf($lock).'/vendor/fixture/pkg', 0o755);
        unlink($this->evictingOf($lock).'/vendor/fixture/pkg/code.php');
    }

    private function plantOrphanPins(string ...$locks): void
    {
        foreach ($locks as $lock) {
            new Filesystem()->dumpFile($this->directory.'/store/.pins/'.$this->key($lock)->value.'/pin.lock', '');
        }
    }

    /**
     * @return list<string>
     */
    private function pinFolders(): array
    {
        $names = array_values(array_diff((array) scandir($this->directory.'/store/.pins'), ['.', '..']));
        sort($names);

        return array_map(strval(...), $names);
    }

    /**
     * @return list<string>
     */
    private function entriesOfStore(): array
    {
        $names = array_values(array_diff((array) scandir($this->directory.'/store'), ['.', '..']));
        sort($names);

        return array_map(strval(...), $names);
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

    // Wherever a pin's file lands, in the store or in the shared lock folder, so a pin left behind is counted either way.
    /**
     * @return list<string>
     */
    private function pinFiles(): array
    {
        $pins = is_dir($this->directory.'/store/.pins') ? array_filter($this->pathsUnder($this->directory.'/store/.pins'), is_file(...)) : [];

        $locks = glob($this->directory.'/locks/*dependency-snapshot-*');

        return [...array_values($pins), ...(false === $locks ? [] : $locks)];
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
