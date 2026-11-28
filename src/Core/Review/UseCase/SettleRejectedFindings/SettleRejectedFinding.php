<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\SettleRejectedFindings;

use Intakt\Core\Platform\Contract\CommandMessage;

// Carries the hash rather than the round's id, because the checkout it was taken from is removed once the round is judged.
final readonly class SettleRejectedFinding implements CommandMessage
{
    public function __construct(
        public string $project,
        public string $text,
        public string $reason,
        public string $file,
        public string $hash,
    ) {
    }
}
