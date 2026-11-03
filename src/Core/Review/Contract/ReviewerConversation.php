<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\Uid\AbstractUid;
use Symfony\Component\Uid\TimeBasedUidInterface;
use Symfony\Component\Uid\Uuid;

// One reviewer role in one round, so two reviewers of a round never share what the model was told.
final readonly class ReviewerConversation
{
    public function __construct(
        public string $rolePrompt,
        public AbstractUid&TimeBasedUidInterface $id,
        public ?Uuid $round = null,
        // The reviewer the ledger and the dashboard name, apart from the conversation, because a restarted reviewer begins a new conversation.
        public ?Uuid $reviewer = null,
    ) {
    }
}
