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

            // Array union keeps the first entry, and an unreadable start adds none, so it cannot take its round's place in the order.
            match ($recorded->event->kind) {
                LedgerEventKind::JudgeDone => $judged[$round] = true,
                default => $started += $this->readableStart($round, $payload, $recorded->recordedAt),
            };
        }

        return array_reverse($this->markJudged($started, $judged));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, ReviewSummary>
     */
    private function readableStart(string $round, array $payload, DateTimeImmutable $startedAt): array
    {
        $project = $payload['project'] ?? null;
        $reviewers = $payload['reviewers'] ?? null;

        if (!is_string($project) || !is_int($reviewers)) {
            return [];
        }

        return [$round => new ReviewSummary(new RoundStart($round, $project, $reviewers), $startedAt)];
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
