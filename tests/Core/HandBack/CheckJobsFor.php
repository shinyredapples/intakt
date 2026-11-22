<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshotLinks;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshotPins;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshots;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshotUses;
use Intakt\Core\HandBack\UseCase\CheckHandBack\BundleFile;
use Intakt\Core\HandBack\UseCase\CheckHandBack\BundleSecretScan;
use Intakt\Core\HandBack\UseCase\CheckHandBack\CheckHandBack;
use Intakt\Core\HandBack\UseCase\CheckHandBack\IncomingObjects;
use Intakt\Core\HandBack\UseCase\CheckHandBack\RepositoryGit;
use Intakt\Core\HandBack\UseCase\CheckHandBack\StrippedCommits;
use Intakt\Core\HandBack\UseCase\RunCheckJob\CheckDependencies;
use Intakt\Core\HandBack\UseCase\RunCheckJob\CheckedLockFiles;
use Intakt\Core\HandBack\UseCase\RunCheckJob\DependencyJobs;
use Intakt\Core\HandBack\UseCase\RunCheckJob\LostContentCheck;
use Intakt\Core\HandBack\UseCase\RunCheckJob\RunCheckJob;
use Intakt\Core\Job\Contract\JobRunner;
use Intakt\Plugin\Gate\Contract\Gate;
use Intakt\Plugin\PhpGate\Infrastructure\PhpGate;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

// Shared by every test that runs a check, because a check now needs a snapshot store, an install folder and locks, kept in one scratch folder so a test removes them with it.
final readonly class CheckJobsFor
{
    public const string CHECK_SCRIPT = __DIR__.'/../../../docker/job/check.sh';

    public const string INSTALL_SCRIPT = __DIR__.'/../../../docker/job/install.sh';

    public function __construct(private string $folder)
    {
    }

    public function runCheckJob(JobRunner $runner, ?Gate $gate = null): RunCheckJob
    {
        $git = new RepositoryGit(new HardenedGit());
        $gate ??= new PhpGate();

        return new RunCheckJob(
            new CheckHandBack($git, new BundleFile(), new StrippedCommits(), new IncomingObjects(), new BundleSecretScan('gitleaks')),
            new LostContentCheck($git),
            $this->dependencies($runner, $gate),
            $runner,
            $gate,
            new Filesystem(),
            self::CHECK_SCRIPT,
        );
    }

    public function dependencies(JobRunner $runner, Gate $gate): CheckDependencies
    {
        $snapshots = $this->snapshots();
        $installs = new DependencyJobs($runner, $gate, $snapshots, new Filesystem(), $this->locks(), self::INSTALL_SCRIPT, $this->folder.'/dependency-installs');

        return new CheckDependencies($gate, new CheckedLockFiles(new RepositoryGit(new HardenedGit())), $installs, $snapshots);
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
