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
    public function __construct(public array $rounds)
    {
        $this->project = $rounds[0]->project;
        $this->change = $rounds[0]->change;
    }

    public function latest(): ReviewedRound
    {
        return array_last($this->rounds);
    }

    // Only In review and Done, because a review is all the ledger knows of a change until intake and the build lane place tasks in the other columns.
    public function column(): BoardColumn
    {
        return ReviewState::Judged === $this->latest()->state ? BoardColumn::Done : BoardColumn::InReview;
    }

    // Only the latest round counts, because the button starts the round after it on the head the change has now.
    public function source(): ?RoundSource
    {
        return $this->latest()->source;
    }

    // Sent with the button as the page shows it, so a double submit or a page gone stale after another round started is refused instead of opening one more round.
    public function nextNumber(): ?int
    {
        $latest = $this->latest()->number;

        return null === $latest ? null : $latest + 1;
    }

    public function tokens(): TokenFigures
    {
        return array_reduce($this->rounds, static fn (TokenFigures $sum, ReviewedRound $round): TokenFigures => $sum->plus($round->tokens), TokenFigures::none());
    }

    public function cost(): Cost
    {
        return array_reduce($this->rounds, static fn (Cost $sum, ReviewedRound $round): Cost => $sum->plus($round->cost), Cost::none());
    }
}
