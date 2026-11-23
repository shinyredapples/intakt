<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\EscalateRecurringP1;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Ledger\Contract\Mentions;
use Intakt\Core\Review\Contract\ChangeEscalations;
use Intakt\Core\Review\Contract\EscalationOutcome;
use Intakt\Core\Review\Contract\EscalationReviewFailed;
use Intakt\Core\Review\Contract\EscalationStillPending;
use Intakt\Core\Review\Contract\EscalationVerdictKept;
use Intakt\Core\Review\Contract\EscalationVerdictReused;
use Intakt\Core\Review\Contract\RecordedIdentityMalformed;
use Intakt\Core\Review\Contract\RecurringP1Found;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Override;

// Read from the ledger by plain code, because the ledger is what a redelivered message must find to settle its round and key, and a reused verdict counts as the review it stands in for.
final readonly class EscalationsOfChange implements ChangeEscalations
{
    private const array KINDS = [
        LedgerEventKind::RecurringP1Found->value => RecurringP1Found::class,
        LedgerEventKind::EscalationReviewed->value => EscalationVerdictKept::class,
        LedgerEventKind::EscalationVerdictReused->value => EscalationVerdictReused::class,
        LedgerEventKind::EscalationFailed->value => EscalationReviewFailed::class,
    ];

    private const int READS = 3;

    public function __construct(
        private ReviewRounds $rounds,
        private Ledger $ledger,
        private LedgerPayloads $payloads,
    ) {
    }

    #[Override]
    public function outcomeAtLatestHead(string $project, string $change): ?EscalationOutcome
    {
        $rounds = $this->roundsAtTheLatestHead($project, $change);

        // Read again after the ledger, because a round opened on a newer head in between makes a late verdict of the old head no longer the latest head's, and a change that keeps moving has no settled outcome yet.
        for ($read = 1; $read <= self::READS; ++$read) {
            if ([] === $rounds) {
                return null;
            }

            $events = $this->eventsOf($rounds);
            $reread = $this->roundsAtTheLatestHead($project, $change);

            if ($reread === $rounds) {
                return $this->outcomeIn($events, $change);
            }

            $rounds = $reread;
        }

        throw EscalationStillPending::of($change);
    }

    // What the ledger holds for the key in that round, so a redelivered or busy message neither asks a model again nor records its outcome twice.
    public function recordedFor(string $round, string $key): EscalationVerdictKept|EscalationVerdictReused|EscalationReviewFailed|null
    {
        foreach ($this->eventsOf([$round => true]) as $event) {
            if (!$event instanceof RecurringP1Found && $key === $event->key) {
                return $event;
            }
        }

        return null;
    }

    /**
     * @return array<string, true>
     */
    private function roundsAtTheLatestHead(string $project, string $change): array
    {
        $ofChange = $this->rounds->ofEverySpelling($project, $change);
        $latest = array_first($ofChange);

        return $latest instanceof ReviewRound ? $this->roundsAtTheHeadOf($latest, $ofChange) : [];
    }

    // Every round of the change on the latest round's head, in every spelling, because a rejection's re-review is a newer round on the same head and must not hide a confirmation of the round before it.
    /**
     * @param list<ReviewRound> $ofChange
     *
     * @return array<string, true>
     */
    private function roundsAtTheHeadOf(ReviewRound $latest, array $ofChange): array
    {
        $head = $this->headOf($latest);
        $rounds = [$latest->id()->toRfc4122() => true];

        foreach ($ofChange as $round) {
            if (null !== $head && $this->headOf($round) === $head) {
                $rounds[$round->id()->toRfc4122()] = true;
            }
        }

        return $rounds;
    }

    // Null for a round stored before identities were recorded, so it shares no head with another round.
    private function headOf(ReviewRound $round): ?string
    {
        try {
            return $round->identity()->commits->head->hash;
        } catch (RecordedIdentityMalformed) {
            return null;
        }
    }

    /**
     * @param array<string, true> $rounds
     *
     * @return list<RecurringP1Found|EscalationVerdictKept|EscalationVerdictReused|EscalationReviewFailed>
     */
    private function eventsOf(array $rounds): array
    {
        $events = [];

        // Only the events naming one of the rounds, because the ledger holds the events of every round.
        foreach ($this->ledger->readMentioning(new Mentions(...array_map(strval(...), array_keys($rounds)))) as $recorded) {
            $class = self::KINDS[$recorded->event->kind->value] ?? null;
            $event = null === $class ? null : $this->payloads->of($recorded, $class);

            if (($event instanceof RecurringP1Found || $event instanceof EscalationVerdictKept || $event instanceof EscalationVerdictReused || $event instanceof EscalationReviewFailed) && isset($rounds[$event->round])) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @param list<RecurringP1Found|EscalationVerdictKept|EscalationVerdictReused|EscalationReviewFailed> $events
     */
    private function outcomeIn(array $events, string $change): ?EscalationOutcome
    {
        $this->refuseWhilePending($events, $change);
        $outcomes = array_map(static fn (EscalationVerdictKept|EscalationVerdictReused $escalation): EscalationOutcome => $escalation->outcome, $this->verdictsIn($events));

        return in_array(EscalationOutcome::Confirmed, $outcomes, true) ? EscalationOutcome::Confirmed : array_first($outcomes);
    }

    // A recurrence without a reviewed, reused or failed escalation is still queued, so its outcome is not known yet and is never read as no escalation.
    /**
     * @param list<RecurringP1Found|EscalationVerdictKept|EscalationVerdictReused|EscalationReviewFailed> $events
     */
    private function refuseWhilePending(array $events, string $change): void
    {
        $settled = [];

        foreach ($events as $event) {
            if (!$event instanceof RecurringP1Found) {
                $settled[$event->round][$event->key] = true;
            }
        }

        foreach ($events as $event) {
            if ($event instanceof RecurringP1Found && !isset($settled[$event->round][$event->key])) {
                throw EscalationStillPending::of($change);
            }
        }
    }

    /**
     * @param list<RecurringP1Found|EscalationVerdictKept|EscalationVerdictReused|EscalationReviewFailed> $events
     *
     * @return list<EscalationVerdictKept|EscalationVerdictReused>
     */
    private function verdictsIn(array $events): array
    {
        return array_values(array_filter($events, static fn (object $event): bool => $event instanceof EscalationVerdictKept || $event instanceof EscalationVerdictReused));
    }
}
