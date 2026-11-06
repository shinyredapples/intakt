<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewSummary;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\JudgeDone;

final readonly class LedgerReviews
{
    public function __construct(
        private Ledger $ledger,
        private RoundPayloads $payloads,
    ) {
    }

    /**
     * @return list<ReviewSummary>
     */
    public function newestFirst(): array
    {
        $started = [];
        $judged = [];

        foreach ($this->ledger->readInOrder() as $recorded) {
            // Array union keeps the first entry, and an unreadable start adds none, so it cannot take its round's place in the order.
            match ($recorded->event->kind) {
                LedgerEventKind::ReviewStarted => $started += $this->readableStart($recorded),
                LedgerEventKind::JudgeDone => $judged += $this->judgedRound($recorded),
                default => null,
            };
        }

        return array_reverse($this->markJudged($started, $judged));
    }

    /**
     * @return array<string, ReviewSummary>
     */
    private function readableStart(RecordedEvent $recorded): array
    {
        $round = $this->payloads->startOf($recorded);

        if (!$round instanceof ReviewedRound) {
            return [];
        }

        return [$round->round => new ReviewSummary(new RoundStart($round->round, $round->project, $round->reviewers), $round->startedAt)];
    }

    /**
     * @return array<string, true>
     */
    private function judgedRound(RecordedEvent $recorded): array
    {
        $judge = $this->payloads->judgeOf($recorded);

        return $judge instanceof JudgeDone ? [$judge->round => true] : [];
    }

    /**
     * @param array<string, ReviewSummary> $started
     * @param array<string, true>          $judged
     *
     * @return list<ReviewSummary>
     */
    private function markJudged(array $started, array $judged): array
    {
        $reviews = [];

        foreach ($started as $round => $review) {
            $reviews[] = isset($judged[$round]) ? $review->judged() : $review;
        }

        return $reviews;
    }
}
