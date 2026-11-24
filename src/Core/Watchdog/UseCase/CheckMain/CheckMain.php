<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\UseCase\CheckMain;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Watchdog\Contract\Classification;
use Intakt\Core\Watchdog\Contract\MainNotRead;
use Intakt\Core\Watchdog\Contract\WatchedMain;
use Intakt\Core\Watchdog\Domain\CheckedMain;
use Intakt\Core\Watchdog\Domain\CommitClassifier;
use Intakt\Core\Watchdog\Infrastructure\MainHistory;
use Symfony\Component\Filesystem\Filesystem;

// The history is built per call, because the repository and the trusted keys belong to the call and a worker must keep no state between calls.
final readonly class CheckMain
{
    private const int READS = 3;

    public function __construct(
        private HardenedGit $git,
        private Filesystem $filesystem,
    ) {
    }

    public function classified(string $repository, WatchedMain $main, TrustedKeys $keys): Classification
    {
        return new CommitClassifier($this->historyFor($repository, $main, $keys))->classify($main);
    }

    public function checked(string $repository, WatchedMain $main, TrustedKeys $keys): CheckedMain
    {
        $live = $this->historyFor($repository, $main, $keys);
        $head = $live->head();
        // Classified at one pinned head and read again after, because a card must name the head its violations were found on; a main that keeps moving fails the read instead of passing.
        for ($read = 1;; ++$read) {
            $pinned = new MainHistory($this->git, $repository, $keys->owner, $this->filesystem, $keys->peer, $head);
            $classification = new CommitClassifier($pinned)->classify($main);
            $now = $live->head();
            if ($now === $head) {
                return new CheckedMain($classification, $head);
            }
            if (self::READS === $read) {
                throw MainNotRead::because(sprintf('main moved during each of %d reads', self::READS));
            }
            $head = $now;
        }
    }

    private function historyFor(string $repository, WatchedMain $main, TrustedKeys $keys): MainHistory
    {
        $history = new MainHistory($this->git, $repository, $keys->owner, $this->filesystem, $keys->peer);
        $main->mustNameFullCommitsOfLength($history->commitNameLength());

        return $history;
    }
}
