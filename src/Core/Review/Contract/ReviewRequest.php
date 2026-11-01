<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class ReviewRequest
{
    public function __construct(
        public Checkout $checkout,
        public ReviewBrief $brief,
        public ReviewerConversation $conversation,
    ) {
    }
}
