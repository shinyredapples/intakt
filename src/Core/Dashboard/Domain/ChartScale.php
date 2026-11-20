<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The top of a count axis is even, so the middle grid line is a whole count too, and never 0, so a chart of zeros still has a scale.
#[Exclude]
final readonly class ChartScale
{
    private const array STEPS = [1, 2, 4, 6, 8, 10];

    public static function topFor(int $largest): int
    {
        if ($largest <= 2) {
            return 2;
        }

        $power = 10 ** (int) floor(log10($largest));

        return array_find(array_map(static fn (int $step): int => $step * $power, self::STEPS), static fn (int $top): bool => $top >= $largest && 0 === $top % 2) ?? 10 * $power;
    }
}
