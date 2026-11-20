<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class UpNext
{
    public const string NOTHING = 'Nothing running or queued.';

    /**
     * @param list<UpNextRound> $rounds running rounds first, then the rounds waiting to start
     */
    private function __construct(private array $rounds)
    {
    }

    // From the board's own cards, so the list never tells a different story than the board; a judged or ended round is not in progress.
    public static function of(Board $board): self
    {
        $running = [];
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
            $entry = new UpNextRound($round->round, $round->project, $round->change, $roles);

            // A round with no reviewer started has not begun; one whose reviewers all answered waits on the judge, which is neither list.
            if ([] !== $roles) {
                $running[] = $entry;
            } elseif ([] === $round->outcomes) {
                $waiting[] = $entry;
            }
        }

        return new self([...$running, ...$waiting]);
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
