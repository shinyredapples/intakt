<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Uid\Uuid;

// One finding alone, with the task and the diff of the round that flagged it again, because the round's checkout and its review server are gone once the round is judged.
#[Exclude]
final readonly class EscalationRequest
{
    public function __construct(
        public ChangeUnderReview $change,
        public Finding $finding,
        // Carried to the call's usage row, because a round's token count reads its calls by the round id.
        public Uuid $round,
    ) {
    }
}
