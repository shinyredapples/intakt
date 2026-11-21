<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Platform\Contract\CommandMessage;

// Sent when the watcher first records a pull request as handed to Intakt, because a round judged before that was not published, and no later judging would publish its head; the head is the one the forge listed, because round numbers count per spelling of the address and cannot say which round is current.
final readonly class PublishHandedOverChange implements CommandMessage
{
    public function __construct(
        public string $project,
        public string $change,
        public string $head,
    ) {
    }
}
