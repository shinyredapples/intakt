<?php

declare(strict_types=1);

namespace Intakt\Gates;

// A place is the position of the first-parent commit that added a fragment, because only that line says which change reached the branch later.
final readonly class HighlightOrder
{
    private const int UNCOMMITTED = PHP_INT_MAX;

    /**
     * @param array<string, int> $places
     */
    public function __construct(
        private array $places = [],
    ) {
    }

    public function compare(string $a, string $b): int
    {
        // Within one place the names only fix where rows go on the page, because two fragments there that set the same row stop the assembly.
        $byPlace = $this->placeOf($a) <=> $this->placeOf($b);

        return 0 === $byPlace ? strnatcmp($a, $b) : $byPlace;
    }

    public function knowsWhichCameFirst(string $a, string $b): bool
    {
        return $this->placeOf($a) !== $this->placeOf($b);
    }

    private function placeOf(string $name): int
    {
        return $this->places[$name] ?? self::UNCOMMITTED;
    }
}
