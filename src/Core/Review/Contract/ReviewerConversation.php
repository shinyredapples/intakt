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
        // Set on a retry, because the live view shows the reviewer under the same place and must replace what the first try streamed there.
        public bool $askedAgain = false,
        // Set on the retry after a changes or blocked answer that named no finding, because a reviewer asked again in the same words tends to answer the same way.
        public bool $askedToNameFindings = false,
        // Carried by every retry, because a retry in the code lane would show the Technical writer the code rules instead of its own.
        public ReviewLane $lane = ReviewLane::Code,
    ) {
    }
}
