<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageSettled;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageStarted;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageUnsettled;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Only the started row names the round, so the settled or unsettled row that carries the tokens is tied to it by its call id, wherever it sits in the ledger; excluded from the container because LedgerRounds builds one per read.
#[Exclude]
final class LedgerTokens
{
    /** @var array<string, string> */
    private array $roundOfCall = [];

    /** @var array<string, TokenFigures> */
    private array $usedByCall = [];

    public function add(UsageStarted|UsageSettled|UsageUnsettled $usage): void
    {
        if ($usage instanceof UsageStarted) {
            $this->tieToRound($usage);

            return;
        }

        $this->countUsages($usage);
    }

    public function ofRound(string $round): TokenFigures
    {
        $sum = TokenFigures::none();

        foreach ($this->usedByCall as $call => $used) {
            if (($this->roundOfCall[$call] ?? null) === $round) {
                $sum = $sum->plus($used);
            }
        }

        return $sum;
    }

    private function tieToRound(UsageStarted $started): void
    {
        if (null !== $started->round) {
            $this->roundOfCall[$started->call] = $started->round;
        }
    }

    private function countUsages(UsageSettled|UsageUnsettled $ended): void
    {
        foreach ($ended->usages as $row) {
            $this->usedByCall[$ended->call] = ($this->usedByCall[$ended->call] ?? TokenFigures::none())->plus($row->figures());
        }
    }
}
