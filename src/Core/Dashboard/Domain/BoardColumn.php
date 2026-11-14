<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// Waiting on you comes first, because it is the only column that asks something of the reader; the rest follow the way a task moves (docs/design/05-architecture/13-dashboard.md).
enum BoardColumn: string
{
    case WaitingOnYou = 'Waiting on you';
    case Backlog = 'Backlog';
    case Ready = 'Ready';
    case Planned = 'Planned';
    case Building = 'Building';
    case Checking = 'Checking';
    case InReview = 'In review';
    case Merging = 'Merging';
    case Done = 'Done';

    // Work not started and work finished ask nothing of the reader now, so they give their width to the flow (docs/design/mocks/DESIGN.md, Rules).
    public function startsCollapsed(): bool
    {
        return match ($this) {
            self::Backlog, self::Done => true,
            default => false,
        };
    }
}
