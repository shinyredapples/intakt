<?php

declare(strict_types=1);

namespace Intakt\Plugin\Gate\Contract;

use ArrayIterator;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

// At least one, because a check without a step would pass every head.
/**
 * @implements IteratorAggregate<int, string>
 */
final readonly class CheckSteps implements IteratorAggregate
{
    /** @var list<string> */
    private array $steps;

    public function __construct(string ...$steps)
    {
        if ([] === $steps) {
            throw new InvalidArgumentException('A check runs at least one step.');
        }

        $this->steps = array_values($steps);
    }

    /**
     * @return Traversable<int, string>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->steps);
    }
}
