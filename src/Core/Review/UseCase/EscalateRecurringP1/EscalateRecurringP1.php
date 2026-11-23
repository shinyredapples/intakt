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
    ) {
    }
}
