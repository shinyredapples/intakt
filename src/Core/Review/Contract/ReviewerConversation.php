<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Uid\AbstractUid;
use Symfony\Component\Uid\TimeBasedUidInterface;
use Symfony\Component\Uid\Uuid;

// One reviewer role in one round, so two reviewers of a round never share what the model was told.
#[Exclude]
final readonly class ReviewerConversation
{
    public function __construct(
        public string $rolePrompt,
        public AbstractUid&TimeBasedUidInterface $id,
        public ?Uuid $round = null,
        // The id the ledger records for the reviewer, apart from the conversation's, because a stop request names the reviewer and a retry starts a new conversation.
        public ?Uuid $reviewerId = null,
        // Set on a retry, because the live view keeps what the first try streamed under the same reviewer and must show where the retry starts.
        public bool $askedAgain = false,
    ) {
    }
}
