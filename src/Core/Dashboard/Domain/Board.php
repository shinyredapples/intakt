<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class Board
{
    // Twelve, because DESIGN.md's crowded column shows 12 cards before "Show n more".
    public const int PAGE_LIMIT = 12;

    /** @var list<Review> */
    private array $cards;

    // The build's tasks come apart from the cards, because a task has no card until the build lane lands, yet it ends the first day (#1792, D5) and keeps its project awake while in flight (docs/design/05-architecture/08-merge.md, Adaptive intervals).
    /**
     * @param list<Review>    $reviews
     * @param list<BuildTask> $tasks
     */
    public function __construct(array $reviews, private array $tasks = [])
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

    // Longest running first, because that is the order of "Meanwhile" in docs/design/mocks/waiting.html.
    /**
     * @return list<Review>
     */
    public function runningReviews(): array
    {
        return array_reverse(array_values(array_filter($this->cards, static fn (Review $card): bool => $card->activeRound() instanceof ReviewedRound)));
    }

    // From the cards in their order, so the newest review's reviewers come first, with the builds in flight the headline counts.
    public function whoIsWorking(): WhoIsWorking
    {
        return new WhoIsWorking($this->cards, count($this->tasksInFlight()));
    }

    // From the same open rounds Working now shows, so the line never tells a different story than the cards below it.
    public function headline(): Headline
    {
        return new Headline($this->whoIsWorking(), count($this->cards) + count($this->tasks), count($this->tasksInFlight()));
    }

    // The first day is an install with no tasks and no reviews (#1792, D5), so a task the build has recorded ends it, in flight or at rest, before any review has run.
    public function isFirstDay(): bool
    {
        return [] === $this->cards && [] === $this->tasks;
    }

    // One home for the choice, because the page and the pushed board both show it. Idle is "no build or review job running" (docs/design/05-architecture/08-merge.md, Adaptive intervals): no task in flight and every card Done, since no column holds ready tasks yet (#2732).
    public function mascot(): ?MascotPose
    {
        return match (true) {
            $this->isFirstDay() => MascotPose::Waiting,
            [] === $this->tasksInFlight() && $this->everyColumnButDoneIsEmpty() => MascotPose::Asleep,
            default => null,
        };
    }

    // The projects at rest while another works, so the project filter can show the asleep octopus for one of them: every card Done and no task in flight (#2732).
    /**
     * @return list<string>
     */
    public function idleProjects(): array
    {
        $busy = [
            ...array_map(static fn (BuildTask $task): string => $task->project, $this->tasksInFlight()),
            ...array_map(static fn (Review $card): string => $card->project, array_filter($this->cards, static fn (Review $card): bool => BoardColumn::Done !== $card->column())),
        ];
        $projects = [
            ...array_map(static fn (Review $card): string => $card->project, $this->cards),
            ...array_map(static fn (BuildTask $task): string => $task->project, $this->tasks),
        ];

        return array_values(array_diff(array_unique($projects), $busy));
    }

    // From the counts over every project, so the project filter never hides or shows a column.
    /**
     * @return list<BoardColumn>
     */
    public function shownColumns(): array
    {
        return $this->splitColumns()['shown'];
    }

    /**
     * @return list<BoardColumn>
     */
    public function comingNext(): array
    {
        return $this->splitColumns()['folded'];
    }

    // The newest card while the board is at rest (every card Done, no task in flight), so a quiet phone home can open it instead of an empty Waiting on you (#3142).
    public function newestReviewAtRest(): ?Review
    {
        return [] !== $this->cards && MascotPose::Asleep === $this->mascot() ? $this->cards[0] : null;
    }

    // Null while the column holds a card, because only an empty column says what lands there and when (#2731).
    public function emptyLineOf(BoardColumn $column): ?string
    {
        return [] === $this->cardsIn($column) ? $column->emptyLine() : null;
    }

    // The build's tasks first, then the reviews with a round still running, so the cards read in the path's order (UX2 home, #3326). A judged change is Done and a round that ended without a judge's call has nobody running, so neither is shown as in progress.
    /**
     * @return list<WorkingCard>
     */
    public function workingNow(): array
    {
        return [
            ...array_map(WorkingCard::ofTask(...), $this->tasksInFlight()),
            ...array_map(WorkingCard::ofReview(...), array_values(array_filter($this->cardsIn(BoardColumn::InReview), static fn (Review $review): bool => $review->activeRound() instanceof ReviewedRound))),
        ];
    }

    // Null while a card works, because Working now then shows the cards instead; the words are the first day's, so the board keeps one wording for this line (#2731). Its own line, not the Status page's, because Working now lists changes and the Status page lists reviewers (#3386).
    public function nobodyWorkingNowLine(): ?string
    {
        return [] === $this->workingNow() ? 'Nobody is working yet: reviewers appear here while they review a change' : null;
    }

    // Null while a reviewer runs, because the Status page then lists the reviewers instead.
    public function nobodyWorkingLine(): ?string
    {
        return [] === $this->whoIsWorking()->lanes() ? 'No reviewer is working right now: reviewers appear here while they review a change' : null;
    }

    // Done opens while every other column is empty, so a finished review is seen on arrival instead of behind its strip.
    public function startsCollapsed(BoardColumn $column): bool
    {
        if (BoardColumn::Done === $column && $this->everyColumnButDoneIsEmpty()) {
            return false;
        }

        return $column->startsCollapsed();
    }

    // Done is the last column and can lie past the edge of a narrow board, so opening it alone is not enough to show the finished review on arrival.
    public function startsScrolledToDone(): bool
    {
        return $this->everyColumnButDoneIsEmpty() && 0 < $this->countOf(BoardColumn::Done);
    }

    // Every card of every project, so the page limit on the cards shown never lowers the count.
    public function countOf(BoardColumn $column): int
    {
        return count($this->cardsIn($column));
    }

    /**
     * @return list<BuildTask>
     */
    private function tasksInFlight(): array
    {
        return array_values(array_filter($this->tasks, static fn (BuildTask $task): bool => $task->inFlight));
    }

    // One pass for both lists, because a column the board shows must never also be named in the Coming next line.
    /**
     * @return array{shown: list<BoardColumn>, folded: list<BoardColumn>}
     */
    private function splitColumns(): array
    {
        $split = ['shown' => [], 'folded' => []];
        foreach (BoardColumn::cases() as $column) {
            $split[$column->foldsIntoComingNext($this->countOf($column)) ? 'folded' : 'shown'][] = $column;
        }

        return $split;
    }

    private function everyColumnButDoneIsEmpty(): bool
    {
        return array_all(BoardColumn::cases(), fn (BoardColumn $column): bool => BoardColumn::Done === $column || 0 === $this->countOf($column));
    }
}
