<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\TokenCount;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Only the started row names the round, so the settled or unsettled row that carries the tokens is tied to it by its call id, wherever it sits in the ledger; excluded from the container because LedgerRounds builds one per read.
#[Exclude]
final class LedgerTokens
{
    /** @var array<string, string> */
    private array $roundOfCall = [];

    /** @var array<string, TokenCount> */
    private array $usedByCall = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function add(array $payload): void
    {
        $call = $payload['call'] ?? null;

        if (!is_string($call)) {
            return;
        }

        $this->tieToRound($call, $payload);
        $this->countUsages($call, $payload['usages'] ?? null);
    }

    public function ofRound(string $round): TokenCount
    {
        $sum = TokenCount::none();

        foreach ($this->usedByCall as $call => $used) {
            if (($this->roundOfCall[$call] ?? null) === $round) {
                $sum = $sum->plus($used);
            }
        }

        return $sum;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function tieToRound(string $call, array $payload): void
    {
        $round = $payload['round'] ?? null;

        if ('started' === ($payload['phase'] ?? null) && is_string($round)) {
            $this->roundOfCall[$call] = $round;
        }
    }

    private function countUsages(string $call, mixed $usages): void
    {
        if (!is_array($usages)) {
            return;
        }

        foreach (array_filter($usages, is_array(...)) as $row) {
            $this->usedByCall[$call] = ($this->usedByCall[$call] ?? TokenCount::none())->plus(new TokenCount($this->count($row, 'prompt'), $this->count($row, 'completion'), $this->count($row, 'total')));
        }
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function count(array $row, string $key): int
    {
        $count = $row[$key] ?? null;

        return is_int($count) ? $count : 0;
    }
}
