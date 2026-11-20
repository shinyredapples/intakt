<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class UpNext
{
    public const string NOTHING = 'Nothing running or queued.';

    /**
     * @param list<UpNextRound> $rounds
     */
    private function __construct(private array $rounds)
    {
    }

    // From the board's own cards, so the list never tells a different story than the board; a judged or ended round is not in progress.
    public static function of(Board $board): self
    {
        $inProgress = [];
        $waiting = [];

        foreach ($board->cardsIn(BoardColumn::InReview) as $card) {
            $round = $card->activeRound();

            if (!$round instanceof ReviewedRound) {
                continue;
            }

            $roles = array_values(array_map(
                static fn (ReviewerOutcome $outcome): string => $outcome->role,
                array_filter($round->outcomes, static fn (ReviewerOutcome $outcome): bool => ReviewerProgress::Running === $outcome->progress),
            ));
            $entry = new UpNextRound($round->round, $round->project, $round->change, self::stateOf($round, $roles), $roles);

            if ($entry->state->isInProgress()) {
                $inProgress[] = $entry;
            } else {
                $waiting[] = $entry;
            }
        }

        return new self([...$inProgress, ...$waiting]);
    }

    // A round stays listed until the judge is done or the round ends, because the board keeps it In review all that time, also once no reviewer runs.
    /**
     * @param list<string> $running
     */
    private static function stateOf(ReviewedRound $round, array $running): UpNextState
    {
        return match (true) {
            [] !== $running => UpNextState::Running,
            [] === $round->outcomes => UpNextState::Waiting,
            count($round->outcomes) >= $round->reviewers => UpNextState::Judging,
            default => UpNextState::BetweenReviewers,
        };
    }

    /**
     * @return list<UpNextRound>
     */
    public function rounds(): array
    {
        return $this->rounds;
    }

    public function isEmpty(): bool
    {
        return [] === $this->rounds;
    }
}
