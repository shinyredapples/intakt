<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Domain;

use Intakt\Core\Watchdog\Contract\Classification;
use Intakt\Core\Watchdog\Contract\CommitHistory;
use Intakt\Core\Watchdog\Contract\MainViolation;
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
            return new Classification([new MainViolation($main->lastSeenHead, Violation::HistoryRewritten)]);
        }

        return new Classification($this->violationsOnTheWalk($main));
    }

    /**
     * @return list<MainViolation>
     */
    private function violationsOnTheWalk(WatchedMain $main): array
    {
        $violations = [];
        foreach ($this->history->firstParentsSince($main->lastSeenHead) as $commit) {
            $violation = $this->judge($main, $commit);
            if (null !== $violation) {
                $violations[] = new MainViolation($commit, $violation);
            }
        }

        return $violations;
    }

    // A recorded head passes before its signature is read, because Intakt's own merge is trusted by its record, not by a key.
    private function judge(WatchedMain $main, string $commit): ?Violation
    {
        if ($main->isRecordedHead($commit)) {
            return null;
        }

        return match ($this->history->signatureOf($commit)) {
            SignatureStatus::OwnerSigned => null,
            SignatureStatus::Unsigned => Violation::Unsigned,
            SignatureStatus::UnknownKey => Violation::UnknownKey,
        };
    }
}
