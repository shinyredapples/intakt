<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use ArrayIterator;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

// The names of the tools a review server offers, because module contracts carry typed objects and never lists of strings.
/**
 * @implements IteratorAggregate<int, string>
 */
final readonly class ReviewTools implements IteratorAggregate
{
    /** @var non-empty-list<string> */
    private array $items;

    /**
     * @throws InvalidArgumentException
     */
    // Refused when empty, because a CLI allowed no tool of the server could never read the change through it.
    public function __construct(string ...$items)
    {
        $items = array_values($items);
        if ([] === $items) {
            throw new InvalidArgumentException('A review server offers at least one tool.');
        }

        $this->items = $items;
    }

    /**
     * @return Traversable<int, string>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}
