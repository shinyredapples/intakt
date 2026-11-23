<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Domain;

use Intakt\Core\Watchdog\Contract\Classification;
use Intakt\Core\Watchdog\Contract\CommitHistory;
use Intakt\Core\Watchdog\Contract\MainHistoryRewritten;
use Intakt\Core\Watchdog\Contract\MainViolation;
use Intakt\Core\Watchdog\Contract\RecordedMerge;
use Intakt\Core\Watchdog\Contract\SignatureStatus;
use Intakt\Core\Watchdog\Contract\Violation;
use Intakt\Core\Watchdog\Contract\WatchedMain;
use NoDiscard;

final readonly class CommitClassifier
{
    public function __construct(private CommitHistory $history)
    {
    }

    #[NoDiscard]
    public function classify(WatchedMain $main): Classification
    {
        if (!$this->history->contains($main->lastSeenHead)) {
            return $this->rewritten($main);
        }

        try {
            return $this->classifiedWalk($main);
        } catch (MainHistoryRewritten) {
            // The same outcome as above, because a force push between the two reads rewrote main just the same.
            return $this->rewritten($main);
        }
    }

    private function rewritten(WatchedMain $main): Classification
    {
        return new Classification([new MainViolation($main->lastSeenHead, Violation::HistoryRewritten)]);
    }

    private function classifiedWalk(WatchedMain $main): Classification
    {
        $walk = $this->history->firstParentsSince($main->lastSeenHead);
        $covered = [] === $walk ? [] : $this->broughtInByRecordedMerges($main->recordedMerges);

        $violations = [];
        $ownerPushes = [];
        foreach ($walk as $commit) {
            $status = $this->judge($main, $covered, $commit);
            if ($status instanceof Violation) {
                $violations[] = new MainViolation($commit, $status);
            }
            if (SignatureStatus::OwnerSigned === $status) {
                $ownerPushes[] = $commit;
            }
        }

        return new Classification($violations, $ownerPushes);
    }

    /**
     * @param list<RecordedMerge> $merges
     *
     * @return array<string, true>
     */
    private function broughtInByRecordedMerges(array $merges): array
    {
        $covered = [];
        foreach ($merges as $merge) {
            foreach ($this->history->broughtInBy($merge) as $commit) {
                $covered[$commit] = true;
            }
        }

        return $covered;
    }

    // A recorded head, and what its merge brought in above its base, pass before any signature is read, because Intakt's own merge is trusted by its record, not by a key.
    /**
     * @param array<string, true> $covered
     */
    private function judge(WatchedMain $main, array $covered, string $commit): Violation|SignatureStatus|null
    {
        if ($main->isRecordedHead($commit) || isset($covered[$commit])) {
            return null;
        }

        return match ($this->history->signatureOf($commit)) {
            SignatureStatus::OwnerSigned => SignatureStatus::OwnerSigned,
            SignatureStatus::Unsigned => Violation::Unsigned,
            SignatureStatus::UnknownKey => Violation::UnknownKey,
        };
    }
}
