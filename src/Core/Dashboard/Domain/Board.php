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

    public function hasNoReview(): bool
    {
        return [] === $this->cards;
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

    // From the same lanes and Waiting on you cards the page shows, so the line never tells a different story than the board below it.
    public function headline(): Headline
    {
        return new Headline($this->whoIsWorking(), $this->cardsIn(BoardColumn::WaitingOnYou), count($this->cards));
    }

    // A card is a review, so a board without one is an install on which no review has run yet.
    public function isFirstDay(): bool
    {
        return [] === $this->cards;
    }

    // One home for the choice, because the page and the pushed board both show it. Idle is read board-wide, since no column holds a project's ready tasks yet: nobody works and every card is Done (#2658).
    public function mascot(): ?MascotPose
    {
        return match (true) {
            $this->isFirstDay() => MascotPose::Waiting,
            0 === $this->whoIsWorking()->working() && $this->everyColumnButDoneIsEmpty() => MascotPose::Asleep,
            default => null,
        };
    }

    // The projects whose every review is Done, so the project filter shows the asleep octopus for one of them while another project still works (#2658). A working review is never Done, so none of them has a lane.
    /**
     * @return list<string>
     */
    public function idleProjects(): array
    {
        $projects = array_values(array_unique(array_map(static fn (Review $card): string => $card->project, $this->cards)));

        return array_values(array_filter($projects, fn (string $project): bool => array_all($this->cards, static fn (Review $card): bool => $card->project !== $project || BoardColumn::Done === $card->column())));
    }

    // Done opens while every other column is empty, so a finished review is seen on arrival instead of behind its strip.
    public function startsCollapsed(BoardColumn $column): bool
    {
        if (BoardColumn::Done === $column && $this->everyColumnButDoneIsEmpty()) {
            return false;
        }

        return $column->startsCollapsed();
    }

    // Done is the last of nine columns and lies past the edge of a laptop's board, so opening it alone is not enough to show the finished review on arrival.
    public function startsScrolledToDone(): bool
    {
        return $this->everyColumnButDoneIsEmpty() && 0 < $this->countOf(BoardColumn::Done);
    }

    // Every card of every project, so the page limit on the cards shown never lowers the count.
    public function countOf(BoardColumn $column): int
    {
        return count($this->cardsIn($column));
    }

    private function everyColumnButDoneIsEmpty(): bool
    {
        return array_all(BoardColumn::cases(), fn (BoardColumn $column): bool => BoardColumn::Done === $column || 0 === $this->countOf($column));
    }
}
