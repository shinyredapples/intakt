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

    // The column's id on the page is col- and this, and the column map and the phone switcher name it the same way, so a jump finds it.
    public function slug(): string
    {
        return str_replace(' ', '-', strtolower($this->value));
    }

    // Work not started and work finished ask nothing of the reader now, so they give their width to the flow (docs/design/mocks/DESIGN.md, Rules).
    public function startsCollapsed(): bool
    {
        return match ($this) {
            self::Backlog, self::Done => true,
            default => false,
        };
    }

    // The feature that fills these columns is not running yet, so while empty they give their width to what works today and the board names them in one Coming next line instead (#3154); a column that holds an item shows again.
    public function foldsIntoComingNext(int $count): bool
    {
        return 0 === $count && match ($this) {
            self::Backlog, self::Ready, self::Planned, self::Building, self::Checking, self::Merging => true,
            default => false,
        };
    }

    // What the column will hold and when, because "Nothing here" tells a newcomer nothing about the board, on the first day or after (#2067, D7; #2731).
    public function emptyLine(): string
    {
        return match ($this) {
            self::WaitingOnYou => 'What only you can decide appears here when Intakt asks you',
            self::Backlog => 'Tasks appear here when Intakt takes in an issue',
            self::Ready => 'Tasks appear here once they are ready to plan',
            self::Planned => 'Tasks appear here once their plan is made',
            self::Building => 'Tasks appear here while an agent builds them',
            self::Checking => 'Tasks appear here while their checks run',
            self::InReview => 'Reviews appear here while a panel reviews a change',
            self::Merging => 'Tasks appear here while they wait to merge',
            self::Done => 'Reviews appear here once the judge has decided them',
        };
    }
}
