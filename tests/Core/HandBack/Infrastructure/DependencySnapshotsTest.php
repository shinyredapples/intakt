<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\Infrastructure;

use FilesystemIterator;
use Intakt\Core\HandBack\Domain\DependencySnapshotKey;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshotCountRefused;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshotLease;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshots;
use Intakt\Core\Job\Contract\JobEgress;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobInput;
use Intakt\Core\Job\Contract\JobLimits;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Core\Job\Infrastructure\EgressRoute;
use Intakt\Core\Job\Infrastructure\JobContainerDefinition;
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

#[CoversClass(DependencySnapshots::class)]
#[CoversClass(DependencySnapshotLease::class)]
#[CoversClass(DependencySnapshotCountRefused::class)]
#[UsesClass(DependencySnapshotKey::class)]
#[UsesClass(DependencyStep::class)]
#[UsesClass(JobInput::class)]
#[UsesClass(JobContainerDefinition::class)]
#[UsesClass(JobSpec::class)]
#[UsesClass(JobId::class)]
#[UsesClass(JobLimits::class)]
#[UsesClass(JobEgress::class)]
#[UsesClass(EgressRoute::class)]
final class DependencySnapshotsTest extends TestCase
{
    private const string START_CHECK = __DIR__.'/../../../../docker/job/start-check.sh';

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
        $hostConfig = new JobContainerDefinition(self::START_CHECK)->of($spec, $this->directory.'/output', EgressRoute::none())['HostConfig'];

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

    // File locks, as the application's default lock store uses, and a factory of its own per store, as two orchestrator processes would have.
    private function store(int $count = 10): DependencySnapshots
    {
        return new DependencySnapshots($this->directory.'/store', $count, new LockFactory(new FlockStore($this->directory.'/locks')));
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

        return [...array_values($pins), ...(array) glob($this->directory.'/locks/*dependency-snapshot-*')];
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
