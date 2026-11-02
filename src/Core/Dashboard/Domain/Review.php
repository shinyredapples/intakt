<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

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

    // Only the latest round counts, because the button starts the round after it on the head the change has now.
    public function source(): ?RoundSource
    {
        return array_last($this->rounds)->source;
    }

    // Asked for by the button, so a double submit or a page gone stale after another round started is refused instead of opening one more round.
    public function nextNumber(): ?int
    {
        $latest = array_last($this->rounds)->number;

        return null === $latest ? null : $latest + 1;
    }

    public function tokens(): TokenCount
    {
        return array_reduce($this->rounds, static fn (TokenCount $sum, ReviewedRound $round): TokenCount => $sum->plus($round->tokens), TokenCount::none());
    }
}
