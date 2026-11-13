<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\UseCase\CheckMain;

use Intakt\Core\Watchdog\Contract\MainNotRead;
use Intakt\Core\Watchdog\Contract\MainVerdict;
use Intakt\Core\Watchdog\Contract\Violation;
use Intakt\Core\Watchdog\Contract\ViolationKind;
use Intakt\Core\Watchdog\Contract\WatchedMain;
use Intakt\Core\Watchdog\Domain\CommitClassifier;
use Intakt\Core\Watchdog\Infrastructure\MainHistory;
use NoDiscard;

final readonly class CheckMain
{
    public function __construct(
        private MainHistory $history,
        private CommitClassifier $classifier,
    ) {
    }

    /**
     * @throws MainNotRead
     */
    #[NoDiscard]
    public function of(string $repository, WatchedMain $main): MainVerdict
    {
        $head = $this->history->headOf($repository);

        if ($this->wasRewritten($repository, $main, $head)) {
            return new MainVerdict($head, [new Violation(ViolationKind::HistoryRewritten, (string) $main->checkedUpTo)]);
        }

        $commits = $this->history->commitsOnMain($repository, $head, $main);

        return new MainVerdict($head, $this->classifier->violationsIn($commits, $main->recordedMerges));
    }

    /**
     * @throws MainNotRead
     */
    private function wasRewritten(string $repository, WatchedMain $main, string $head): bool
    {
        return null !== $main->checkedUpTo && !$this->history->stillHolds($repository, $main->checkedUpTo, $head);
    }
}
