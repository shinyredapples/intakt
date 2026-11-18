<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\HandBack\UseCase\CheckHandBack\RepositoryGit;
use Intakt\Core\HandBack\UseCase\RunCheckJob\DependencyInstallJob;
use Intakt\Core\HandBack\UseCase\RunCheckJob\DependencySnapshots;
use Intakt\Core\Job\Contract\JobRunner;
use Intakt\Plugin\Gate\Contract\Gate;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

// The store as the container wires it, on a folder of the test's own, so a check job test never reaches a live store.
final readonly class DependencyStore
{
    public const string INSTALL_SCRIPT = __DIR__.'/../../../docker/job/install-dependencies.sh';

    public static function in(string $root, JobRunner $runner, Gate $gate): DependencySnapshots
    {
        return new DependencySnapshots(
            $gate,
            new RepositoryGit(new HardenedGit()),
            new DependencyInstallJob($runner, $gate, new Filesystem(), self::INSTALL_SCRIPT),
            new LockFactory(new InMemoryStore()),
            new MockClock(),
            new Filesystem(),
            $root,
            10,
        );
    }
}
