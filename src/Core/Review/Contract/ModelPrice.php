<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

// A model's list prices by kind of token as one value, because module contracts carry typed objects and never arrays of scalars.
/**
 * @implements IteratorAggregate<string, int|float>
 */
final readonly class ModelPrice implements IteratorAggregate
{
    /**
     * @param array<string, int|float> $amounts
     */
    private function __construct(
        private array $amounts,
    ) {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function with(string $kind, int|float $amount): self
    {
        return new self([...$this->amounts, $kind => $amount]);
    }

    /**
     * @return Traversable<string, int|float>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->amounts);
    }
}
