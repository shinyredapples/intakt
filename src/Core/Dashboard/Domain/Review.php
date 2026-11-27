<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Intakt\Core\Budget\Contract\Cost;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;

final readonly class Review
{
    public string $project;
    public string $change;

    /**
     * @param non-empty-list<ReviewedRound> $rounds
     */
    public function __construct(public array $rounds, private Timeline $timeline = Timeline::Current)
    {
        $this->project = $rounds[0]->project;
        $this->change = $rounds[0]->change;
    }

    // Derived from the rounds and never stored, because one live round means some verdict of the change was given on it.
    public function everyRoundIsAReplay(): bool
    {
        return array_all($this->rounds, static fn (ReviewedRound $round): bool => $round->isReplay());
    }

    // Lowest number, then lowest id, and not the first element, because the reader puts the round of the address first among rounds of equal number (older rounds have none), and every page of one review must name the same round.
    public function firstRound(): string
    {
        $ids = array_map(static fn (ReviewedRound $round): string => $round->round, $this->rounds);
        $numbers = array_map(static fn (ReviewedRound $round): int => $round->number ?? 0, $this->rounds);
        array_multisort($numbers, SORT_NUMERIC, $ids, SORT_STRING);

        return $ids[0];
    }

    public function latest(): ReviewedRound
    {
        return array_last($this->rounds);
    }

    // Only In review and Done, because a review is all the ledger knows of a change until intake and the build lane place tasks in the other columns.
    public function column(): BoardColumn
    {
        return $this->latest()->isJudged() ? BoardColumn::Done : BoardColumn::InReview;
    }

    // Only the latest round counts, because the button starts the round after it on the head the change has now.
    public function source(): ?RoundSource
    {
        return $this->latest()->source;
    }

    // Sent with the button as the page shows it, so a double submit or a page gone stale after another round started is refused instead of opening one more round.
    public function nextNumber(): ?int
    {
        if (Timeline::HistoryOnly === $this->timeline) {
            return null;
        }

        $latest = $this->latest()->number;

        return null === $latest ? null : $latest + 1;
    }

    // Null once the judge has called the latest round or it ended without a call, because nothing is then in progress.
    public function activeRound(): ?ReviewedRound
    {
        $latest = $this->latest();

        return $latest->isJudged() || $latest->hasEnded() ? null : $latest;
    }

    // Null when the round belongs to another review or is not judged yet, so a finding's page never shows a stranger's finding or one without the judge's call.
    public function judgedRound(string $round): ?ReviewedRound
    {
        return array_find($this->rounds, static fn (ReviewedRound $candidate): bool => $candidate->round === $round && $candidate->isJudged());
    }

    // Null before the first judge's call, because only a judged round has findings to carry forward.
    public function lastJudgedRound(): ?ReviewedRound
    {
        $judged = array_filter($this->rounds, static fn (ReviewedRound $round): bool => $round->isJudged());

        return [] === $judged ? null : array_last($judged);
    }

    /**
     * @return list<RoundFinding>
     */
    // A follow-up waits for a later task, so only a "fix now" finding stays open into the next round.
    public function openFindings(): array
    {
        $judged = $this->lastJudgedRound();

        return $judged instanceof ReviewedRound ? $judged->actFindings() : [];
    }

    public function tokens(): TokenFigures
    {
        return array_reduce($this->rounds, static fn (TokenFigures $sum, ReviewedRound $round): TokenFigures => $sum->plus($round->tokens), TokenFigures::none());
    }

    public function cost(): Cost
    {
        return array_reduce($this->rounds, static fn (Cost $sum, ReviewedRound $round): Cost => $sum->plus($round->cost), Cost::none());
    }

    /**
     * @return list<PathStep>
     */
    public function path(): array
    {
        return ReviewPath::of($this);
    }
}
