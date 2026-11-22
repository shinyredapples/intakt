<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Infrastructure {
    use Intakt\Tests\Core\HandBack\Infrastructure\DependencySnapshotsTest;

    // Fails the publication only in a store that holds the marker file, because no real folder fails a rename on demand; any other rename passes through.
    function rename(string $from, string $to): bool
    {
        if (DependencySnapshots::INCOMING === basename($from) && is_file(dirname($to).'/'.DependencySnapshotsTest::REFUSE_PUBLICATION)) {
            return false;
        }

        return DependencySnapshotsTest::renamedForReal($from, $to);
    }

    // Fails only for an entry named after the marker, because a file's owner can always change its mode; any other path passes through.
    function chmod(string $path, int $mode): bool
    {
        if (DependencySnapshotsTest::REFUSES_CHMOD === basename($path)) {
            return false;
        }

        return DependencySnapshotsTest::changedForReal($path, $mode);
    }
}

namespace Intakt\Tests\Core\HandBack\Infrastructure {
    use Intakt\Core\HandBack\Domain\DependencySnapshotKey;
    use Intakt\Core\HandBack\Infrastructure\DependencySnapshotCountRefused;
    use Intakt\Core\HandBack\Infrastructure\DependencySnapshotLease;
    use Intakt\Core\HandBack\Infrastructure\DependencySnapshotLinks;
    use Intakt\Core\HandBack\Infrastructure\DependencySnapshots;
    use Intakt\Core\HandBack\Infrastructure\DependencySnapshotUses;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\UsesClass;
    use PHPUnit\Framework\TestCase;
    use RecursiveDirectoryIterator;
    use RecursiveIteratorIterator;
    use SplFileInfo;
    use Symfony\Component\Filesystem\Exception\IOException;
    use Symfony\Component\Filesystem\Filesystem;
    use Symfony\Component\Lock\LockFactory;
    use Symfony\Component\Lock\Store\FlockStore;

    #[CoversClass(DependencySnapshots::class)]
    #[CoversClass(DependencySnapshotLease::class)]
    #[CoversClass(DependencySnapshotCountRefused::class)]
    #[CoversClass(DependencySnapshotUses::class)]
    #[CoversClass(DependencySnapshotLinks::class)]
    #[UsesClass(DependencySnapshotKey::class)]
    final class DependencySnapshotsTest extends TestCase
    {
        public const string REFUSE_PUBLICATION = 'refuse-publication';

        public const string REFUSES_CHMOD = 'refuses-chmod';

        private string $folder;

        private string $store;

        protected function setUp(): void
        {
            $this->folder = sys_get_temp_dir().'/'.uniqid('intakt-snapshots-', true);
            $this->store = $this->folder.'/store';
            new Filesystem()->mkdir($this->folder.'/locks');
        }

        protected function tearDown(): void
        {
            foreach ($this->foldersUnder($this->folder) as $folder) {
                self::changedForReal($folder, 0o700);
            }

            new Filesystem()->remove($this->folder);
        }

        public function testASavedSnapshotIsLeasedFromTheFolderOfItsKey(): void
        {
            $store = $this->storeKeeping(10);
            $store->save($this->key('a'), $this->install('a'));

            $lease = $store->lease($this->key('a'));

            self::assertInstanceOf(DependencySnapshotLease::class, $lease);
            self::assertSame($this->store.'/'.$this->key('a')->value, $lease->folder);
            self::assertSame('a', file_get_contents($lease->folder.'/vendor/autoload.php'));
        }

        public function testALeaseOfAKeyTheStoreDoesNotHoldIsAMiss(): void
        {
            self::assertNull($this->storeKeeping(10)->lease($this->key('missing')));
        }

        public function testNoBuilderOrCheckUserCanWriteTheStoreOrASnapshot(): void
        {
            $this->storeKeeping(10)->save($this->key('a'), $this->install('a'));
            clearstatcache();

            self::assertSame(0o700, fileperms($this->store) & 0o777);
            $snapshot = $this->store.'/'.$this->key('a')->value;
            $writable = array_filter([$snapshot, ...$this->entriesUnder($snapshot)], static fn (string $path): bool => 0 !== (fileperms($path) & 0o222));
            self::assertSame([], $writable);
            self::assertSame(0o555, fileperms($snapshot.'/vendor/bin/tool') & 0o777);
            self::assertSame(0o444, fileperms($snapshot.'/vendor/autoload.php') & 0o777);
        }

        public function testItsOwnerCannotChangeOrAddAnEntryInsideASnapshot(): void
        {
            if (0 === posix_geteuid()) {
                self::markTestSkipped('root can write through any mode');
            }

            $store = $this->storeKeeping(10);
            $store->save($this->key('a'), $this->install('a'));
            $snapshot = $this->store.'/'.$this->key('a')->value;

            self::assertFalse(@file_put_contents($snapshot.'/vendor/autoload.php', 'changed'));
            self::assertFalse(@file_put_contents($snapshot.'/vendor/new.php', 'new'));
            self::assertFalse(@mkdir($snapshot.'/extra'));
            self::assertSame(0o700, fileperms($this->store) & 0o777);
            self::assertSame('a', file_get_contents($snapshot.'/vendor/autoload.php'));
        }

        public function testAnAbsoluteLinkIntoTheInstallStillWorksWhenTheInstallIsGone(): void
        {
            $install = $this->install('a');
            new Filesystem()->symlink($install.'/vendor/autoload.php', $install.'/vendor/bin/absolute-link');
            $store = $this->storeKeeping(10);
            $store->save($this->key('a'), $install);
            new Filesystem()->remove($install);

            $lease = $store->lease($this->key('a'));

            self::assertInstanceOf(DependencySnapshotLease::class, $lease);
            self::assertSame('a', file_get_contents($lease->folder.'/vendor/bin/absolute-link'));
            self::assertSame('../autoload.php', readlink($lease->folder.'/vendor/bin/absolute-link'));
            self::assertSame('../autoload.php', readlink($lease->folder.'/vendor/bin/autoload-link'));
        }

        public function testALinkOutOfTheInstallRefusesTheSaveAndLeavesNoSnapshot(): void
        {
            foreach (['/usr/bin/env', '../../outside'] as $target) {
                $install = $this->install('a');
                new Filesystem()->symlink($target, $install.'/vendor/escape');

                try {
                    $this->storeKeeping(10)->save($this->key('a'), $install);
                    self::fail('the save of an install with a link out of it must be refused');
                } catch (IOException) {
                    self::assertSame([], $this->snapshots());
                }

                new Filesystem()->remove($install);
            }
        }

        public function testALinkThatClimbsOutThroughAnotherLinkRefusesTheSave(): void
        {
            $install = $this->install('a');
            new Filesystem()->mkdir($install.'/vendor/a/b');
            new Filesystem()->symlink('../..', $install.'/vendor/a/b/up');
            new Filesystem()->symlink('a/b/up/../..', $install.'/vendor/esc');

            $this->expectException(IOException::class);

            try {
                $this->storeKeeping(10)->save($this->key('a'), $install);
            } finally {
                self::assertSame([], $this->snapshots());
            }
        }

        public function testALinkThatStepsUpFromALinkToTheInstallRootRefusesTheSave(): void
        {
            $install = $this->install('a');
            new Filesystem()->dumpFile(dirname($install).'/sentinel', 'outside');
            new Filesystem()->symlink('.', $install.'/alias');
            new Filesystem()->symlink('alias/../sentinel', $install.'/lookup');

            $this->expectException(IOException::class);

            try {
                $this->storeKeeping(10)->save($this->key('a'), $install);
            } finally {
                self::assertSame([], $this->snapshots());
            }
        }

        public function testADanglingLinkRefusesTheSave(): void
        {
            $install = $this->install('a');
            new Filesystem()->symlink('missing.php', $install.'/vendor/dangling');

            $this->expectException(IOException::class);

            $this->storeKeeping(10)->save($this->key('a'), $install);
        }

        public function testALinkThroughAnotherLinkThatStaysInsideIsKeptAsItsResolvedPath(): void
        {
            $install = $this->install('a');
            new Filesystem()->symlink('bin', $install.'/vendor/alias');
            new Filesystem()->symlink('alias/../autoload.php', $install.'/vendor/through');
            new Filesystem()->symlink('.', $install.'/root');
            $store = $this->storeKeeping(10);
            $store->save($this->key('a'), $install);
            new Filesystem()->remove($install);

            $lease = $store->lease($this->key('a'));

            self::assertInstanceOf(DependencySnapshotLease::class, $lease);
            self::assertSame('autoload.php', readlink($lease->folder.'/vendor/through'));
            self::assertSame('a', file_get_contents($lease->folder.'/vendor/through'));
            self::assertSame('.', readlink($lease->folder.'/root'));
        }

        public function testAFileExecutableByItsGroupOnlyStaysExecutableInTheSnapshot(): void
        {
            $install = $this->install('a');
            new Filesystem()->chmod($install.'/vendor/autoload.php', 0o654);
            $this->storeKeeping(10)->save($this->key('a'), $install);

            self::assertSame(0o555, fileperms($this->store.'/'.$this->key('a')->value.'/vendor/autoload.php') & 0o777);
        }

        public function testADamagedUseOrderCountsAsNothingRecorded(): void
        {
            $store = $this->storeKeeping(10);
            $store->save($this->key('a'), $this->install('a'));
            file_put_contents($this->store.'/'.DependencySnapshotUses::FILE, '{broken');

            $store->save($this->key('b'), $this->install('b'));

            self::assertSame($this->names('a', 'b'), $this->snapshots());
        }

        public function testTheLeastRecentlyUsedSnapshotIsEvictedPastTheCount(): void
        {
            $store = $this->storeKeeping(2);
            $store->save($this->key('a'), $this->install('a'));
            $store->save($this->key('b'), $this->install('b'));
            $store->lease($this->key('a'))?->release();

            $store->save($this->key('c'), $this->install('c'));

            self::assertSame($this->names('a', 'c'), $this->snapshots());
        }

        public function testACountBelowOneIsRefused(): void
        {
            $this->expectExceptionObject(DependencySnapshotCountRefused::belowOne(0));

            $this->storeKeeping(0);
        }

        public function testASaveNeverEvictsTheSnapshotItSaves(): void
        {
            $wide = $this->storeKeeping(3);
            $wide->save($this->key('a'), $this->install('a'));
            $wide->save($this->key('b'), $this->install('b'));
            $wide->save($this->key('c'), $this->install('c'));

            $this->storeKeeping(1)->save($this->key('a'), $this->install('a'));

            self::assertSame($this->names('a'), $this->snapshots());
        }

        public function testALeaseNeverEvictsTheSnapshotItLeases(): void
        {
            $wide = $this->storeKeeping(3);
            $wide->save($this->key('a'), $this->install('a'));
            $wide->save($this->key('b'), $this->install('b'));
            $wide->save($this->key('c'), $this->install('c'));

            $lease = $this->storeKeeping(1)->lease($this->key('a'));

            self::assertInstanceOf(DependencySnapshotLease::class, $lease);
            self::assertSame($this->names('a'), $this->snapshots());
        }

        public function testAFinishedInstallIsPublishedWhenEveryOtherSnapshotIsLeased(): void
        {
            $store = $this->storeKeeping(1);
            $store->save($this->key('a'), $this->install('a'));
            $first = $store->lease($this->key('a'));
            $store->save($this->key('b'), $this->install('b'));
            $second = $store->lease($this->key('b'));

            $store->save($this->key('c'), $this->install('c'));

            self::assertNotNull($first);
            self::assertNotNull($second);
            self::assertSame($this->names('a', 'b', 'c'), $this->snapshots());
        }

        public function testReleasedLeasesLetTheStoreShrinkBackToTheCount(): void
        {
            $store = $this->storeKeeping(1);
            $store->save($this->key('a'), $this->install('a'));
            $lease = $store->lease($this->key('a'));
            $store->save($this->key('b'), $this->install('b'));
            self::assertNotNull($lease);
            $lease->release();

            $store->save($this->key('c'), $this->install('c'));

            self::assertSame($this->names('c'), $this->snapshots());
        }

        public function testAnEvictedSnapshotTakesItsPinFolderWithIt(): void
        {
            $store = $this->storeKeeping(1);
            $store->save($this->key('a'), $this->install('a'));
            $store->lease($this->key('a'))?->release();
            self::assertDirectoryExists($this->pinFolderOf('a'));

            $store->save($this->key('b'), $this->install('b'));

            self::assertDirectoryDoesNotExist($this->pinFolderOf('a'));
            self::assertDirectoryDoesNotExist($this->store.'/'.$this->key('a')->value);
        }

        public function testAPinHeldByACheckKeepsTheSnapshotAndItsPinFolder(): void
        {
            $store = $this->storeKeeping(1);
            $store->save($this->key('a'), $this->install('a'));
            $pin = new LockFactory(new FlockStore($this->pinFolderOf('a')))->createLock('pin', ttl: null);
            self::assertTrue($pin->acquireRead(false));

            $store->save($this->key('b'), $this->install('b'));

            self::assertSame($this->names('a', 'b'), $this->snapshots());
            self::assertDirectoryExists($this->pinFolderOf('a'));
            $pin->release();
        }

        public function testAFailedPublicationLeavesNoFolderUnderTheKey(): void
        {
            $store = $this->storeKeeping(10);
            $store->save($this->key('a'), $this->install('a'));
            touch($this->store.'/'.self::REFUSE_PUBLICATION);

            try {
                $store->save($this->key('b'), $this->install('b'));
                self::fail('the publication was not refused');
            } catch (IOException $failure) {
                self::assertStringContainsString('could not be published', $failure->getMessage());
            }

            self::assertSame($this->names('a'), $this->snapshots());
            self::assertDirectoryExists($this->store.'/'.DependencySnapshots::INCOMING);
        }

        public function testTheNextSaveRemovesWhatAFailedPublicationLeft(): void
        {
            $store = $this->storeKeeping(10);
            new Filesystem()->mkdir($this->store);
            touch($this->store.'/'.self::REFUSE_PUBLICATION);

            try {
                $store->save($this->key('a'), $this->install('a'));
                self::fail('the publication was not refused');
            } catch (IOException $failure) {
                self::assertStringContainsString('could not be published', $failure->getMessage());
            }

            unlink($this->store.'/'.self::REFUSE_PUBLICATION);
            $store->save($this->key('b'), $this->install('b'));

            self::assertDirectoryDoesNotExist($this->store.'/'.DependencySnapshots::INCOMING);
            self::assertSame($this->names('b'), $this->snapshots());
        }

        public function testAModeThatCannotBeSetEndsTheSaveBeforeTheSnapshotHasAKey(): void
        {
            $install = $this->install('a');
            touch($install.'/vendor/'.self::REFUSES_CHMOD);

            try {
                $this->storeKeeping(10)->save($this->key('a'), $install);
                self::fail('the save did not fail');
            } catch (IOException $failure) {
                self::assertStringContainsString(self::REFUSES_CHMOD, $failure->getMessage());
            }

            self::assertSame([], $this->snapshots());
        }

        public function testAUseOrderThatIsNoListOfNamesCountsAsNothingRecorded(): void
        {
            $store = $this->storeKeeping(10);
            $store->save($this->key('a'), $this->install('a'));

            foreach (['{"a":1}', '[1,2]', '"a"'] as $order) {
                file_put_contents($this->store.'/'.DependencySnapshotUses::FILE, $order);
                $store->save($this->key('b'), $this->install('b'));
            }

            self::assertSame($this->names('a', 'b'), $this->snapshots());
        }

        // Called from the overrides, because only a call outside their namespace reaches the real function.
        public static function renamedForReal(string $from, string $to): bool
        {
            return rename($from, $to);
        }

        public static function changedForReal(string $path, int $mode): bool
        {
            return chmod($path, $mode);
        }

        private function storeKeeping(int $count): DependencySnapshots
        {
            return new DependencySnapshots($this->store, $count, new LockFactory(new FlockStore($this->folder.'/locks')), new Filesystem(), new DependencySnapshotUses(new Filesystem()), new DependencySnapshotLinks(new Filesystem()));
        }

        private function key(string $lock): DependencySnapshotKey
        {
            return DependencySnapshotKey::ofLockInputs(['composer.lock' => $lock], '8.5.12');
        }

        private function install(string $name): string
        {
            $install = $this->folder.'/installs/'.$name;
            $filesystem = new Filesystem();
            $filesystem->dumpFile($install.'/vendor/autoload.php', $name);
            $filesystem->dumpFile($install.'/vendor/bin/tool', '#!/bin/sh');
            $filesystem->chmod($install.'/vendor/bin/tool', 0o755);
            $filesystem->symlink('../autoload.php', $install.'/vendor/bin/autoload-link');

            return $install;
        }

        private function pinFolderOf(string $lock): string
        {
            return $this->store.'/'.DependencySnapshots::PINS.'/'.$this->key($lock)->value;
        }

        /**
         * @return list<string>
         */
        private function names(string ...$locks): array
        {
            $names = array_map(fn (string $lock): string => $this->key($lock)->value, $locks);
            sort($names);

            return $names;
        }

        /**
         * @return list<string>
         */
        private function snapshots(): array
        {
            $names = array_values(array_filter(
                is_dir($this->store) ? scandir($this->store) : [],
                static fn (string $name): bool => 1 === preg_match('/\A[0-9a-f]{64}\z/', $name),
            ));
            sort($names);

            return $names;
        }

        /**
         * @return list<string>
         */
        private function entriesUnder(string $folder): array
        {
            $entries = [];

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
                assert($entry instanceof SplFileInfo);

                if (!$entry->isLink()) {
                    $entries[] = $entry->getPathname();
                }
            }

            return $entries;
        }

        /**
         * @return list<string>
         */
        private function foldersUnder(string $folder): array
        {
            if (!is_dir($folder)) {
                return [];
            }

            self::changedForReal($folder, 0o700);
            $folders = [];

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
                assert($entry instanceof SplFileInfo);

                if ($entry->isDir() && !$entry->isLink()) {
                    self::changedForReal($entry->getPathname(), 0o700);
                    $folders[] = $entry->getPathname();
                }
            }

            return $folders;
        }
    }
}
