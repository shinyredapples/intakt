<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\EscalateRecurringP1;

use Intakt\Core\Platform\Contract\CommandMessage;

// Only ids and the key, because the stored round holds the rest and a message that waits in the queue stays valid across a restart.
final readonly class EscalateRecurringP1 implements CommandMessage
{
    public function __construct(
        public string $round,
        public string $finding,
        public string $key,
        // The hash of the finding's file as the round's checkout held it, taken when the escalation is queued, because that checkout is removed once the round is judged and a rejection is settled with it; null when it could not be read, and then nothing is settled.
        public ?string $codeHash = null,
    ) {
    }
}
