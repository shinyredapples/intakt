<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

// The finished splits of one project as one value, because module contracts carry typed objects and never lists.
final readonly class FinishedSplits
{
    /** @var list<FinishedSplit> */
    private array $splits;

    public function __construct(FinishedSplit ...$splits)
    {
        $this->splits = array_values($splits);
    }

    /**
     * @return list<FinishedSplit>
     */
    public function all(): array
    {
        return $this->splits;
    }
}
