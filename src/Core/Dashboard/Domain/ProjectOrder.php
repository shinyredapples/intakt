<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// One order for the project cards and the project filter, because the owner reads the same projects in both. The layout's script ports it (base.html.twig).
final class ProjectOrder
{
    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    public static function sorted(array $names): array
    {
        $names = array_values(array_unique($names));
        usort($names, self::compare(...));

        return $names;
    }

    // Names that differ only in what the natural order ignores come in byte order, because a stable sort would leave each list in the order its own source names them.
    private static function compare(string $one, string $other): int
    {
        $natural = strnatcasecmp($one, $other);

        return 0 === $natural ? strcmp($one, $other) : $natural;
    }
}
