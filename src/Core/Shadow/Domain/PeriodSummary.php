<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\Domain;

use Intakt\Core\Shadow\Contract\HeadComparison;
use Intakt\Core\Shadow\Contract\ShadowSummary;
use NoDiscard;

final readonly class PeriodSummary
{
    /**
     * @param list<HeadComparison> $heads
     */
    #[NoDiscard('a summary that is not read reports nothing')]
    public function summarize(array $heads): ShadowSummary
    {
        return new ShadowSummary(
            heads: count($heads),
            matched: $this->sum($heads, static fn (HeadComparison $head): int => count($head->matches)),
            loopOnly: $this->sum($heads, static fn (HeadComparison $head): int => count($head->loopOnly)),
            intaktOnly: $this->sum($heads, static fn (HeadComparison $head): int => count($head->intaktOnly)),
            severityDisagreements: $this->sum($heads, static fn (HeadComparison $head): int => $head->severityDisagreements()),
            intaktFailedReviews: $this->sum($heads, static fn (HeadComparison $head): int => $head->intaktFailedReviews()),
            unreadableLoopReviews: $this->sum($heads, static fn (HeadComparison $head): int => $head->unreadableLoopReviews()),
            intaktReviews: $this->sum($heads, static fn (HeadComparison $head): int => $head->intaktReviews()),
            intaktTokens: $this->sum($heads, static fn (HeadComparison $head): int => $head->intaktTokens),
        );
    }

    /**
     * @param list<HeadComparison>          $heads
     * @param callable(HeadComparison): int $count
     */
    private function sum(array $heads, callable $count): int
    {
        return array_sum(array_map($count, $heads));
    }
}
