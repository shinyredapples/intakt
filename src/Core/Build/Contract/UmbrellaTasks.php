<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

// The numbers of one project's split originals as one value, because module contracts carry typed objects and never lists of numbers.
final readonly class UmbrellaTasks
{
    /** @var list<int> */
    private array $numbers;

    public function __construct(int ...$numbers)
    {
        $this->numbers = array_values($numbers);
    }

    public function contains(int $task): bool
    {
        return in_array($task, $this->numbers, true);
    }
}
