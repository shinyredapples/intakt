<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// Plain code over the lanes and Waiting on you, never a model, so the line a newcomer reads first says only what the board holds.
final readonly class Headline
{
    // Only reviewers are lanes until the build lane lands, so every running change is the Code reviewers' (docs/design/06-decisions.md, decision 23).
    private const string REVIEWERS = 'Code reviewers';

    // Two, because the headline holds at most two clauses and the waiting count (#1942); the rest is counted.
    private const int NAMED = 2;

    // Cut because a long name would stretch the line over several lines on a wide screen too; the lanes and cards show it whole.
    private const int NAME_WIDTH = 32;

    /**
     * @param list<Review> $waiting
     */
    public function __construct(
        private WhoIsWorking $working,
        private array $waiting,
        private int $cards,
    ) {
    }

    // The sentence before the waiting count, apart so the page can mark the count with the needs-you style.
    public function opening(): string
    {
        if (0 === $this->cards) {
            return 'Nothing is running yet. Start the first review.';
        }

        return $this->running();
    }

    // Counts the running changes instead of naming them, because named changes can take three lines on a phone and the line must stay within two (#2055, D4).
    public function shortOpening(): string
    {
        $running = count($this->runningChanges());

        if (0 === $running) {
            return $this->opening();
        }

        return 'The '.self::REVIEWERS.' are reviewing '.$running.' '.(1 === $running ? 'change' : 'changes').'.';
    }

    // Asked by the line's partial, because the first-day opening asks for the first review and so links the first-run page (#2067, D3).
    public function isFirstDay(): bool
    {
        return 0 === $this->cards;
    }

    // The first lane's round, because the opening names that lane's change first, so the lead opens the review it names (#2207, D1).
    public function runningRound(): ?string
    {
        return array_first($this->working->lanes())?->roundId;
    }

    public function waitingCount(): int
    {
        return count($this->waiting);
    }

    // Without the number, because the page prints the number as the needs-you count; empty on a fresh install, whose opening says it all.
    public function waitingWords(): string
    {
        if (0 === $this->cards) {
            return '';
        }

        return match ($this->waitingCount()) {
            0 => 'Nothing needs you.',
            1 => 'needs you:',
            default => 'need you:',
        };
    }

    // The first card of Waiting on you, because the headline names it and the Waiting on you page lists only owner notices so far.
    public function firstWaiting(): ?Review
    {
        return array_first($this->waiting);
    }

    private function running(): string
    {
        $changes = $this->runningChanges();

        if ([] === $changes) {
            return 'Nothing is running.';
        }

        return 'The '.self::REVIEWERS.' are reviewing '.$this->named($changes).'.';
    }

    /**
     * @return list<string>
     */
    private function runningChanges(): array
    {
        $places = [];

        foreach ($this->working->lanes() as $lane) {
            $places[$this->placeKey($lane)] ??= $this->place($lane);
        }

        return array_values($places);
    }

    // Length-prefixed so that no two names meet in one key, and a string key so that "1" and "01" stay apart.
    private function placeKey(Lane $lane): string
    {
        if ('' === $lane->change) {
            return 'round:'.strlen($lane->roundId).':'.$lane->roundId;
        }

        return 'change:'.strlen($lane->project).':'.$lane->project.strlen($lane->change).':'.$lane->change;
    }

    // A round from before the ledger named its change has no change, so the round names it, as on its board card.
    private function place(Lane $lane): string
    {
        $name = '' === $lane->change ? 'round '.$this->short($lane->roundId) : 'change '.$this->short($lane->change);

        return $name.' of '.$this->short($lane->project);
    }

    /**
     * @param non-empty-list<string> $changes
     */
    private function named(array $changes): string
    {
        $named = array_slice($changes, 0, self::NAMED);
        $rest = count($changes) - count($named);

        if (0 === $rest) {
            return implode(' and ', $named);
        }

        return implode(', ', $named).' and '.$rest.' more '.(1 === $rest ? 'change' : 'changes');
    }

    private function short(string $name): string
    {
        return mb_strimwidth($name, 0, self::NAME_WIDTH, '…');
    }
}
