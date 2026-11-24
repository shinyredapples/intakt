<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// Plain code over the open rounds, never a model, so the line a newcomer reads first says only what the board holds.
final readonly class Headline
{
    // Only reviewers are lanes until the build lane lands, so every running change is the Code reviewers' (docs/design/06-decisions.md, decision 23).
    private const string REVIEWERS = 'Code reviewers';

    // Two, because the headline holds at most two clauses (#1942); the rest is counted.
    private const int NAMED = 2;

    // Cut because a long name would stretch the line over several lines on a wide screen too; the Working now cards and the board's cards show it whole.
    private const int NAME_WIDTH = 32;

    public function __construct(
        private WhoIsWorking $working,
        private int $cards,
        private int $builds = 0,
    ) {
    }

    // What the board runs; nothing of what waits, because the Needs you card says that (UX2 home, #3326).
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

    // Asked by the line's partial, because the first-day opening asks for the first review and so links the setup wizard (#2067, D3).
    public function isFirstDay(): bool
    {
        return 0 === $this->cards;
    }

    // The first open round, because the opening names its change first, so the lead opens the review it names (#2207, D1).
    public function runningRound(): ?string
    {
        return array_first($this->working->activeRounds())?->round;
    }

    private function running(): string
    {
        $changes = $this->runningChanges();

        // A build has no lane yet, so it is counted, never named, and the line never says nothing runs while one is in flight (#2732).
        if ([] === $changes) {
            return match ($this->builds) {
                0 => 'Nothing is running.',
                1 => 'A build is running.',
                default => $this->builds.' builds are running.',
            };
        }

        return 'The '.self::REVIEWERS.' are reviewing '.$this->named($changes).'.';
    }

    /**
     * @return list<string>
     */
    private function runningChanges(): array
    {
        $places = [];

        foreach ($this->working->activeRounds() as $round) {
            $places[$this->placeKey($round)] ??= $this->place($round);
        }

        return array_values($places);
    }

    // Length-prefixed so that no two names meet in one key, and a string key so that "1" and "01" stay apart.
    private function placeKey(ReviewedRound $round): string
    {
        if ('' === $round->change) {
            return 'round:'.strlen($round->round).':'.$round->round;
        }

        return 'change:'.strlen($round->project).':'.$round->project.strlen($round->change).':'.$round->change;
    }

    // A round from before the ledger named its change has no change, so the round names it, as on its board card.
    private function place(ReviewedRound $round): string
    {
        $name = '' === $round->change ? 'round '.$this->short($round->round) : 'change '.$this->short($round->change);

        return $name.' of '.$this->short($round->project);
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
