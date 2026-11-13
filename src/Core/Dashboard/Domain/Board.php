<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class Board
{
    // Twelve, because DESIGN.md's crowded column shows 12 cards before "Show n more".
    public const int PAGE_LIMIT = 12;

    /** @var list<Review> */
    private array $cards;

    /**
     * @param list<Review> $reviews
     */
    public function __construct(array $reviews)
    {
        usort($reviews, static fn (Review $one, Review $other): int => $other->latest()->startedAt <=> $one->latest()->startedAt);
        $this->cards = $reviews;
    }

    /**
     * @return list<Review>
     */
    public function cardsIn(BoardColumn $column): array
    {
        return array_values(array_filter($this->cards, static fn (Review $card): bool => $card->column() === $column));
    }

    // From the cards in their order, so the newest review's reviewers come first.
    public function whoIsWorking(): WhoIsWorking
    {
        return new WhoIsWorking($this->cards);
    }

    // Every card of every project, so the page limit on the cards shown never lowers the count.
    public function countOf(BoardColumn $column): int
    {
        return count($this->cardsIn($column));
    }
}
