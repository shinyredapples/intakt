<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class ReviewRequest
{
    public function __construct(
        public Checkout $checkout,
        public ReviewBrief $brief,
        public ReviewerConversation $conversation,
        // Null on an install without a container engine, because such an install reviews in the app's own process.
        public ?JobReview $job = null,
    ) {
    }
}
