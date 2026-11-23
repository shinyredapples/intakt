<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\UseCase\RunCheckJob;

use Intakt\Core\HandBack\Domain\DependencySnapshotKey;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshotLease;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshotLinks;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshotPins;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshots;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshotUses;
use Intakt\Core\HandBack\UseCase\RunCheckJob\CheckedLockFile;
use Intakt\Core\HandBack\UseCase\RunCheckJob\CheckSnapshot;
use Intakt\Plugin\Gate\Contract\DependencyStep;
use Intakt\Plugin\PhpGate\Infrastructure\PhpGate;
use Intakt\Tests\Core\HandBack\CheckJobsFor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\FlockStore;

#[CoversClass(CheckSnapshot::class)]
#[UsesClass(CheckedLockFile::class)]
#[UsesClass(DependencySnapshots::class)]
#[UsesClass(DependencySnapshotKey::class)]
#[UsesClass(DependencySnapshotLease::class)]
#[UsesClass(DependencySnapshotLinks::class)]
#[UsesClass(DependencySnapshotPins::class)]
#[UsesClass(DependencySnapshotUses::class)]
#[UsesClass(DependencyStep::class)]
#[UsesClass(PhpGate::class)]
final class CheckSnapshotTest extends TestCase
{
    private string $folder;

    private DependencySnapshotKey $key;

    public function testTheLockFileArgumentsComeInPairsOfPathThenBlob(): void
    {
        $snapshot = $this->snapshot();

        self::assertSame(['composer.json', str_repeat('a', 40), 'composer.lock', str_repeat('b', 40)], $snapshot->lockFileArguments());
        $snapshot->release();
    }

    public function testAHeldSnapshotKeepsItsPinTaken(): void
    {
        $snapshot = $this->snapshot();

        self::assertFalse($this->pin()->acquire());
        $snapshot->release();
    }

    public function testAReleasedSnapshotFreesItsPin(): void
    {
        $this->snapshot()->release();

        self::assertTrue($this->pin()->acquire());
    }

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/intakt-check-snapshot-'.bin2hex(random_bytes(6));
        $this->key = DependencySnapshotKey::ofLockInputs(['composer.lock' => "{}\n"], '8.5.12');
    }

    protected function tearDown(): void
    {
        // Writable again first, because the store keeps every snapshot read-only.
        new Filesystem()->chmod($this->folder, 0o700, 0o000, true);
        new Filesystem()->remove($this->folder);
    }

    private function snapshot(): CheckSnapshot
    {
        $snapshots = new CheckJobsFor($this->folder)->snapshots();
        new Filesystem()->dumpFile($this->folder.'/install/vendor/autoload.php', "<?php\n");
        $snapshots->save($this->key, $this->folder.'/install');
        $lease = $snapshots->lease($this->key);
        self::assertNotNull($lease);
        $step = new PhpGate()->dependencyStep(null);
        self::assertNotNull($step);

        return new CheckSnapshot($lease, $step, ['composer.json' => new CheckedLockFile(str_repeat('a', 40), "{}\n"), 'composer.lock' => new CheckedLockFile(str_repeat('b', 40), "{}\n")], '8.5.12');
    }

    // Exclusive, as an eviction takes it, so it is refused while a check holds the shared pin.
    private function pin(): SharedLockInterface
    {
        return new LockFactory(new FlockStore($this->folder.'/dependency-snapshots/'.DependencySnapshots::PINS.'/'.$this->key->value))->createLock('pin', ttl: null);
    }
}
