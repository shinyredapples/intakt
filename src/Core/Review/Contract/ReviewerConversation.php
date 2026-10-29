<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\Uid\AbstractUid;
use Symfony\Component\Uid\TimeBasedUidInterface;

// One reviewer role in one round, so two reviewers of a round never share what the model was told.
final readonly class ReviewerConversation
{
    public function __construct(
        public string $rolePrompt,
        public AbstractUid&TimeBasedUidInterface $id,
    ) {
    }
}
