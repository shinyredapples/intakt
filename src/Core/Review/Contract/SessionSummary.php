<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Budget\Contract\Cost;

// Findings are listed under the change's ledger ids, so reported and resolved stay empty until the round completes: before that each reviewer's ids are its own.
final readonly class SessionSummary
{
    /**
     * @param list<ToolCallCount> $toolCalls
     * @param list<string>        $filesRead
     * @param list<Finding>       $checking
     * @param list<Finding>       $reported
     * @param list<Finding>       $resolved
     */
    public function __construct(
        public array $toolCalls,
        public Cost $cost,
        public array $filesRead,
        public array $checking,
        public array $reported,
        public array $resolved,
    ) {
    }

    public function totalToolCalls(): int
    {
        return array_sum(array_map(static fn (ToolCallCount $kind): int => $kind->count, $this->toolCalls));
    }
}
