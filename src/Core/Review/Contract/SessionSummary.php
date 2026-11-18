<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Git\Contract\Paths;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Findings are listed under the change's ledger ids, so reported and resolved stay empty until the round completes: before that each reviewer's ids are its own. Excluded because the summaries build it per read, not the container; the judged flag is data and not a mode.
#[Exclude]
final readonly class SessionSummary
{
    /**
     * @param list<ToolCallCount> $toolCalls
     * @param list<Finding>       $checking
     * @param list<Finding>       $reported
     * @param list<Finding>       $resolved
     */
    public function __construct(
        public array $toolCalls,
        public Cost $cost,
        public Paths $filesRead,
        public array $checking,
        public array $reported,
        public array $resolved,
        // Carried with the summary, because whoever publishes it live must learn in the same read that the round is judged: its final summary goes out with the judged state, and nothing after it.
        public bool $judged,
    ) {
    }

    public function totalToolCalls(): int
    {
        return array_sum(array_map(static fn (ToolCallCount $kind): int => $kind->count, $this->toolCalls));
    }
}
