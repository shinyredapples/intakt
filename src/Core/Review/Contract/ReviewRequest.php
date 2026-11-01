<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\Uid\Uuid;

final readonly class ReviewRequest
{
    public function __construct(
        public Checkout $checkout,
        public ReviewBrief $brief,
        public ReviewerConversation $conversation,
        public ?Uuid $round = null,
    ) {
    }
}
