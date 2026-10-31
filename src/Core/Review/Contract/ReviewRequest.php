<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class ReviewRequest
{
    /**
     * @param list<Finding> $open
     */
    public function __construct(
        public Checkout $checkout,
        public string $task,
        public ReviewerConversation $conversation,
        public array $open = [],
    ) {
    }
}
