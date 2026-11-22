<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack;

use Intakt\Core\HandBack\Infrastructure\DependencySnapshotLinks;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshotPins;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshots;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshotUses;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

// Shared by every test that runs a check, because an install needs a snapshot store, an install folder and locks, kept in one scratch folder so a test removes them with it.
final readonly class CheckJobsFor
{
    public const string INSTALL_SCRIPT = __DIR__.'/../../../docker/job/install.sh';

    public function __construct(private string $folder)
    {
    }

    public function snapshots(): DependencySnapshots
    {
        return new DependencySnapshots(
            $this->folder.'/dependency-snapshots',
            10,
            $this->locks(),
            new Filesystem(),
            new DependencySnapshotUses(new Filesystem()),
            new DependencySnapshotLinks(new Filesystem()),
            new DependencySnapshotPins(new Filesystem()),
        );
    }

    // A new factory on the same folder each time, because two orchestrator processes share one lock folder and not one factory.
    public function locks(): LockFactory
    {
        return new LockFactory(new FlockStore($this->folder.'/locks'));
    }

    public function snapshotsFolder(): string
    {
        return $this->folder.'/dependency-snapshots';
    }
}
