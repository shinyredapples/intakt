<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class Board
{
    // Twelve, because DESIGN.md's crowded column shows 12 cards before "Show n more".
    public const int PAGE_LIMIT = 12;

    /** @var list<Review> */
    private array $cards;

    // Tasks are counted apart from the cards, because a task the build has worked on has no card yet but still ends the first day (#1792, D5). The projects of the tasks in flight come apart too, because a running build is not idle though it has no card (docs/design/05-architecture/08-merge.md, Adaptive intervals).
    /**
     * @param list<Review> $reviews
     * @param list<string> $building one project per task in flight
     */
    public function __construct(array $reviews, private int $tasks, private array $building)
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
        return new Headline($this->whoIsWorking(), $this->cardsIn(BoardColumn::WaitingOnYou), count($this->cards) + $this->tasks, count($this->building));
    }

    // The first day is an install with no tasks and no reviews (#1792, D5), so a task the build has worked on ends it before any review has run.
    public function isFirstDay(): bool
    {
        return [] === $this->cards && 0 === $this->tasks;
    }

    // One home for the choice, because the page and the pushed board both show it. Idle is read board-wide, since no column holds a project's ready tasks yet: no build runs, nobody reviews and every card is Done (#2658).
    public function mascot(): ?MascotPose
    {
        return match (true) {
            $this->isFirstDay() => MascotPose::Waiting,
            [] === $this->building && 0 === $this->whoIsWorking()->working() && $this->everyColumnButDoneIsEmpty() => MascotPose::Asleep,
            default => null,
        };
    }

    // The projects whose every review is Done and that run no build, so the project filter shows the asleep octopus for one of them while another project still works (#2658). A working review is never Done, so none of them has a lane.
    /**
     * @return list<string>
     */
    public function idleProjects(): array
    {
        $projects = array_values(array_unique(array_map(static fn (Review $card): string => $card->project, $this->cards)));

        return array_values(array_filter($projects, fn (string $project): bool => !in_array($project, $this->building, true) && array_all($this->cards, static fn (Review $card): bool => $card->project !== $project || BoardColumn::Done === $card->column())));
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
