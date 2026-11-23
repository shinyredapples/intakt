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
        $named = array_filter(array_map(self::named(...), $labels));

        // The last one, because the labels come in the order they were applied and of two values of the family the one applied last is the owner's latest word.
        return array_last($named) ?? self::Normal;
    }

    // The labels of the family in the order given, because only their order steers: it decides which of them counts.
    /**
     * @param list<string> $labels
     *
     * @return list<string>
     */
    public static function labelsIn(array $labels): array
    {
        return array_values(array_filter($labels, static fn (string $label): bool => self::named($label) instanceof self));
    }

    // Cases are declared from most to least urgent, so the order of the declaration is the rank.
    public function rank(): int
    {
        return (int) array_search($this, self::cases(), true);
    }

    private static function named(string $label): ?self
    {
        return str_starts_with($label, WorkLabels::PRIORITY_PREFIX) ? self::tryFrom(substr($label, strlen(WorkLabels::PRIORITY_PREFIX))) : null;
    }
}
