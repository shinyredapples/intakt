<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\EscalateRecurringP1;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\EscalationReviewFailed;
use Intakt\Core\Review\Contract\EscalationVerdictKept;
use Intakt\Core\Review\Contract\EscalationVerdictReused;

// Read from the ledger, because the ledger is what a redelivered message must find to settle its round and key, and a reused verdict counts as the review it stands in for.
final readonly class EscalationsOfChange
{
    private const array KINDS = [
        LedgerEventKind::EscalationReviewed->value => EscalationVerdictKept::class,
        LedgerEventKind::EscalationVerdictReused->value => EscalationVerdictReused::class,
        LedgerEventKind::EscalationFailed->value => EscalationReviewFailed::class,
    ];

    public function __construct(
        private Ledger $ledger,
        private LedgerPayloads $payloads,
    ) {
    }

    // What the ledger holds for the key in that round, so a redelivered or busy message neither asks a model again nor records its outcome twice.
    public function recordedFor(string $round, string $key): EscalationVerdictKept|EscalationVerdictReused|EscalationReviewFailed|null
    {
        // Only the events naming the round, because the ledger holds the events of every round.
        foreach ($this->ledger->readMentioning([$round]) as $recorded) {
            $class = self::KINDS[$recorded->event->kind->value] ?? null;
            $event = null === $class ? null : $this->payloads->of($recorded, $class);

            if (($event instanceof EscalationVerdictKept || $event instanceof EscalationVerdictReused || $event instanceof EscalationReviewFailed) && $round === $event->round && $key === $event->key) {
                return $event;
            }
        }

        return null;
    }
}
