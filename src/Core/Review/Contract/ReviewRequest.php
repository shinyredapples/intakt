<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class ReviewRequest
{
    /**
     * @param list<Finding> $open the findings still open, each under its persistent ledger id; empty in the first round
     */
    public function __construct(
        public Checkout $checkout,
        public string $task,
        public ReviewerConversation $conversation,
        public array $open = [],
    ) {
    }
}
