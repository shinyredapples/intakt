<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// Who made a History row's change: a person names themself as the actor, anything Intakt does on its own names no one.
enum HistorySource: string
{
    case Dashboard = 'dashboard';
    case Intakt = 'intakt';

    public function filterLabel(): string
    {
        return match ($this) {
            self::Dashboard => 'From the dashboard',
            self::Intakt => 'By Intakt',
        };
    }
}
