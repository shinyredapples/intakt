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
        $named = array_filter(self::cases(), static fn (self $priority): bool => in_array(WorkLabels::PRIORITY_PREFIX.$priority->value, $labels, true));

        // Two values of the family cannot be told apart by age in one listing, so the less urgent one counts and a label never jumps the queue by accident.
        return array_last($named) ?? self::Normal;
    }

    // Cases are declared from most to least urgent, so the order of the declaration is the rank.
    public function rank(): int
    {
        return (int) array_search($this, self::cases(), true);
    }
}
