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

    public function tokens(): TokenCount
    {
        return array_reduce($this->rounds, static fn (TokenCount $sum, ReviewedRound $round): TokenCount => $sum->plus($round->tokens), TokenCount::none());
    }
}
