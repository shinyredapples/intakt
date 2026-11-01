<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Domain\ReviewSummary;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use JsonException;

// Payloads are decoded only here, so the pages need nothing from the Review module that writes them.
final readonly class LedgerReviews
{
    public function __construct(private Ledger $ledger)
    {
    }

    /**
     * @return list<ReviewSummary>
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

            match ($recorded->event->kind) {
                LedgerEventKind::JudgeDone => $judged[$round] = true,
                LedgerEventKind::ReviewStarted => $started[$round] ??= $this->summarize($round, $payload, $recorded->recordedAt),
                default => null,
            };
        }

        $reviews = [];

        foreach (array_filter($started) as $round => $review) {
            $reviews[] = isset($judged[$round]) ? $review->judged() : $review;
        }

        return array_reverse($reviews);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function summarize(string $round, array $payload, DateTimeImmutable $startedAt): ?ReviewSummary
    {
        $project = $payload['project'] ?? null;
        $reviewers = $payload['reviewers'] ?? null;

        if (!is_string($project) || !is_int($reviewers)) {
            return null;
        }

        return new ReviewSummary(new RoundStart($round, $project, $reviewers), $startedAt);
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

        return is_array($payload) ? array_filter($payload, is_string(...), ARRAY_FILTER_USE_KEY) : [];
    }
}
