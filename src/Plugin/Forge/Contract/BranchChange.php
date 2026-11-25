<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

final readonly class BranchChange
{
    /** @var list<string> */
    public array $labels;

    public function __construct(
        public BranchChangeState $state,
        string ...$labels,
    ) {
        $this->labels = array_values($labels);
    }

    public function hasLabel(string $label): bool
    {
        return in_array($label, $this->labels, true);
    }
}
