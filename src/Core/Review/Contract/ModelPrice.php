<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use IteratorAggregate;
use Traversable;

// A model's list prices by kind of token as one value, because module contracts carry typed objects and never arrays of scalars.
/**
 * @implements IteratorAggregate<string, int|float>
 */
final readonly class ModelPrice implements IteratorAggregate
{
    // Pairs instead of an array keyed by kind, because PHP turns a numeric string key into an integer and a spread renumbers it.
    /**
     * @param list<array{string, int|float}> $amounts
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
        $amounts = $this->amounts;
        $known = array_find_key($amounts, static fn (array $pair): bool => $kind === $pair[0]);

        if (null === $known) {
            $amounts[] = [$kind, $amount];
        } else {
            $amounts[$known] = [$kind, $amount];
        }

        return new self($amounts);
    }

    /**
     * @return Traversable<string, int|float>
     */
    public function getIterator(): Traversable
    {
        foreach ($this->amounts as [$kind, $amount]) {
            yield $kind => $amount;
        }
    }
}
