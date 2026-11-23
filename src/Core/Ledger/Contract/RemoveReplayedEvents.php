<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Contract;

use Intakt\Core\Platform\Contract\CommandMessage;
use Symfony\Component\Uid\Uuid;

// The one way a stored event leaves the ledger, only for the rows a replay wrote into a project flagged as a replay; the sender checks the flag, because the ledger knows no projects.
final readonly class RemoveReplayedEvents implements CommandMessage
{
    /**
     * @param list<Uuid> $events
     */
    public function __construct(public array $events)
    {
    }
}
