<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// Read from the payload's actor, because a person who changes something on the dashboard names themself there and Intakt's own events name no one.
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
