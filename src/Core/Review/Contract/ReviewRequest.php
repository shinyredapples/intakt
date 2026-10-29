<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\Uid\AbstractUid;
use Symfony\Component\Uid\TimeBasedUidInterface;

final readonly class ReviewRequest
{
    public function __construct(
        public Checkout $checkout,
        public string $rolePrompt,
        public string $task,
        public AbstractUid&TimeBasedUidInterface $round,
    ) {
    }
}
