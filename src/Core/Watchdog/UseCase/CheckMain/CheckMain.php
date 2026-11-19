<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\UseCase\CheckMain;

use Intakt\Core\Watchdog\Contract\CheckedMain;
use Intakt\Core\Watchdog\Contract\CommitClassification;
use Intakt\Core\Watchdog\Contract\MainNotRead;
use Intakt\Core\Watchdog\Contract\RecordedMerge;
use Intakt\Core\Watchdog\Contract\Verdict;
use Intakt\Core\Watchdog\Contract\WatchedMain;
use NoDiscard;

final readonly class CheckMain
{
    public function __construct(
        private MainHistory $history,
        private CommitSignature $signatures,
    ) {
    }

    /**
     * @throws MainNotRead
     */
    #[NoDiscard]
    public function of(WatchedMain $main): CheckedMain
    {
        $head = $this->history->headOf($main->repository);

        if ($this->wasRewritten($main, $head)) {
            return new CheckedMain($head, [new CommitClassification($head, Verdict::HistoryRewritten)]);
        }

        $commits = $this->history->firstParentCommits($main->repository, $main->lastSeenHead, $head);

        return new CheckedMain($head, array_map(fn (string $commit): CommitClassification => $this->classified($main, $commit), $commits));
    }

    private function wasRewritten(WatchedMain $main, string $head): bool
    {
        return null !== $main->lastSeenHead && !$this->history->isAncestor($main->repository, $main->lastSeenHead, $head);
    }

    /**
     * @throws MainNotRead
     */
    private function classified(WatchedMain $main, string $commit): CommitClassification
    {
        if ($this->isRecordedHead($main, $commit)) {
            return new CommitClassification($commit, Verdict::RecordedMerge);
        }

        $text = $this->history->commitText($main->repository, $commit);

        return new CommitClassification($commit, Verdict::ofSignature($this->signatures->of($text, $main->ownerKeys)));
    }

    private function isRecordedHead(WatchedMain $main, string $commit): bool
    {
        return array_any($main->recordedMerges, static fn (RecordedMerge $merge): bool => $merge->head === $commit);
    }
}
