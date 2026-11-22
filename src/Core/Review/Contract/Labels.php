<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// The labels of a change as one value, because module contracts carry typed objects and never lists of strings.
final readonly class Labels
{
    /** @var list<string> */
    private array $labels;

    public function __construct(string ...$labels)
    {
        $this->labels = array_values($labels);
    }

    public function contains(string $label): bool
    {
        return in_array($label, $this->labels, true);
    }
}
