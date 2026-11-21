<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\UseCase\CheckMain;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Watchdog\Contract\Classification;
use Intakt\Core\Watchdog\Contract\WatchedMain;
use Intakt\Core\Watchdog\Domain\CheckedMain;
use Intakt\Core\Watchdog\Domain\CommitClassifier;
use Intakt\Core\Watchdog\Infrastructure\MainHistory;
use Symfony\Component\Filesystem\Filesystem;

// The history is built per call, because the repository and the owner's keys belong to the call and a worker must keep no state between calls.
final readonly class CheckMain
{
    public function __construct(
        private HardenedGit $git,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @param list<string> $ownerKeys
     */
    public function classified(string $repository, WatchedMain $main, array $ownerKeys): Classification
    {
        return new CommitClassifier($this->historyFor($repository, $main, $ownerKeys))->classify($main);
    }

    /**
     * @param list<string> $ownerKeys
     */
    public function checked(string $repository, WatchedMain $main, array $ownerKeys): CheckedMain
    {
        $history = $this->historyFor($repository, $main, $ownerKeys);
        $head = $history->head();

        return new CheckedMain(new CommitClassifier($history)->classify($main), $head);
    }

    /**
     * @param list<string> $ownerKeys
     */
    private function historyFor(string $repository, WatchedMain $main, array $ownerKeys): MainHistory
    {
        $history = new MainHistory($this->git, $repository, $ownerKeys, $this->filesystem);
        $main->mustNameFullCommitsOfLength($history->commitNameLength());

        return $history;
    }
}
