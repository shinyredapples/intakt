<?php

declare(strict_types=1);

namespace Intakt\Gates;

// A place alternates a position in a line of history with the merged parent (lane) it came from, so fragments on one parent keep their order while fragments from different parents of one merge have none.
final readonly class HighlightOrder
{
    private const array UNCOMMITTED = [PHP_INT_MAX];

    /**
     * @param array<string, list<int>> $places
     */
    public function __construct(
        private array $places = [],
    ) {
    }

    public function compare(string $a, string $b): int
    {
        $placeOfA = $this->placeOf($a);
        $placeOfB = $this->placeOf($b);
        $differing = $this->firstDifferenceOf($placeOfA, $placeOfB);

        return null === $differing ? strnatcmp($a, $b) : $placeOfA[$differing] <=> $placeOfB[$differing];
    }

    public function knowsWhichCameFirst(string $a, string $b): bool
    {
        $differing = $this->firstDifferenceOf($this->placeOf($a), $this->placeOf($b));

        return null !== $differing && 0 === $differing % 2;
    }

    /**
     * @return list<int>
     */
    private function placeOf(string $name): array
    {
        return $this->places[$name] ?? self::UNCOMMITTED;
    }

    /**
     * @param list<int> $a
     * @param list<int> $b
     */
    private function firstDifferenceOf(array $a, array $b): ?int
    {
        foreach ($a as $index => $step) {
            if (!array_key_exists($index, $b)) {
                return null;
            }

            if ($step !== $b[$index]) {
                return $index;
            }
        }

        return null;
    }
}
