<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Budget\Contract\Cost;
use InvalidArgumentException;

// The costs of several rounds as one value, because module contracts carry typed objects and never maps.
final readonly class RoundCostList
{
    /**
     * @param list<RoundCost> $costs
     */
    public function __construct(private array $costs)
    {
    }

    // A round that was not asked for has no figure, because reading it as zero would show a free round.
    public function of(string $roundId): Cost
    {
        return array_find($this->costs, static fn (RoundCost $cost): bool => $cost->roundId === $roundId)?->cost ?? throw new InvalidArgumentException(sprintf('The cost of round "%s" was not asked for.', $roundId));
    }
}
