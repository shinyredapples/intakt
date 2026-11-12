<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

use Intakt\Plugin\GitHubWorkSource\Contract\WorkLabels;

enum Priority: string
{
    case Urgent = 'urgent';
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';

    /**
     * @param list<string> $labels
     */
    public static function of(array $labels): self
    {
        $named = array_filter(array_map(static fn (string $label): ?self => str_starts_with($label, WorkLabels::PRIORITY_PREFIX) ? self::tryFrom(substr($label, strlen(WorkLabels::PRIORITY_PREFIX))) : null, $labels));

        // The labels come in the order they were applied, and of two values of the family the one applied last is the owner's latest word.
        return array_last($named) ?? self::Normal;
    }

    // Cases are declared from most to least urgent, so the order of the declaration is the rank.
    public function rank(): int
    {
        return (int) array_search($this, self::cases(), true);
    }
}
