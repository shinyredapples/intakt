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

    // Cut because a long name would push the line past two lines on a phone; the lanes and cards show it whole.
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

    // Ends with "needs you:" while something waits, so the page can link the words that follow to it.
    public function text(): string
    {
        if ($this->isFresh()) {
            return 'Nothing is running yet. Start the first review.';
        }

        return $this->runningSentence().' '.$this->waitingSentence();
    }

    public function isFresh(): bool
    {
        return 0 === $this->cards;
    }

    // The first card of Waiting on you, because the Waiting on you page (#911) does not exist yet.
    public function firstWaiting(): ?Review
    {
        return array_first($this->waiting);
    }

    public function runningSentence(): string
    {
        $changes = $this->runningChanges();

        if ([] === $changes) {
            return 'Nothing is running.';
        }

        return 'The '.self::REVIEWERS.' are reviewing '.$this->named($changes).'.';
    }

    // The phone's form, because two names already pass two lines at 390 wide (#1942, D4), and the lanes and cards name every change.
    public function countedSentence(): string
    {
        $count = count($this->runningChanges());

        return match ($count) {
            0 => 'Nothing is running.',
            1 => 'The '.self::REVIEWERS.' are reviewing 1 change.',
            default => 'The '.self::REVIEWERS.' are reviewing '.$count.' changes.',
        };
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

    public function waitingSentence(): string
    {
        $count = count($this->waiting);

        if (0 === $count) {
            return 'Nothing needs you.';
        }

        return $count.(1 === $count ? ' needs' : ' need').' you:';
    }

    private function short(string $name): string
    {
        return mb_strimwidth($name, 0, self::NAME_WIDTH, '…');
    }
}
