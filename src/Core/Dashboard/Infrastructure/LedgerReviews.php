<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\ReviewSummary;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use JsonException;

// The dashboard reads the ledger alone; this class is the one place that knows the shape of the payloads it reads.
final readonly class LedgerReviews
{
    public function __construct(private Ledger $ledger)
    {
    }

    /**
     * @return list<ReviewSummary> newest round first
     */
    public function newestFirst(): array
    {
        $started = [];
        $judged = [];

        foreach ($this->ledger->readInOrder() as $recorded) {
            $payload = $this->payloadOf($recorded);
            $round = $payload['round'] ?? null;

            if (!is_string($round)) {
                continue;
            }

            if ($recorded->event->kind === LedgerEventKind::JudgeDone) {
                $judged[$round] = true;
            }

            if ($recorded->event->kind === LedgerEventKind::ReviewStarted) {
                $started[$round] = [$payload, $recorded];
            }
        }

        $reviews = [];

        foreach ($started as $round => [$payload, $recorded]) {
            $review = $this->summarize((string) $round, $payload, $recorded, isset($judged[$round]));

            if ($review instanceof ReviewSummary) {
                $reviews[] = $review;
            }
        }

        return array_reverse($reviews);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function summarize(string $round, array $payload, RecordedEvent $recorded, bool $judged): ?ReviewSummary
    {
        $project = $payload['project'] ?? null;
        $reviewers = $payload['reviewers'] ?? null;

        if (!is_string($project) || !is_int($reviewers)) {
            return null;
        }

        return new ReviewSummary($round, $project, $reviewers, $recorded->recordedAt, $judged);
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadOf(RecordedEvent $recorded): array
    {
        if (!in_array($recorded->event->kind, [LedgerEventKind::ReviewStarted, LedgerEventKind::JudgeDone], true)) {
            return [];
        }

        try {
            $payload = json_decode($recorded->event->payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($payload) ? $payload : [];
    }
}
