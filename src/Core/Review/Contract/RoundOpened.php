<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Platform\Contract\CommandMessage;

// Queued on the Doctrine transport in the round's own transaction, so a worker handles it only once the outermost transaction commits, and never for a round rolled back.
final readonly class RoundOpened implements CommandMessage
{
    public function __construct(
        public string $round,
        public string $project,
    ) {
    }
}
