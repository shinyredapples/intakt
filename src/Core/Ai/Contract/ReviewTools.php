<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

// The names of the tools a review server offers, because module contracts carry typed objects and never lists of strings.
/**
 * @implements IteratorAggregate<int, string>
 */
final readonly class ReviewTools implements IteratorAggregate
{
    /** @var list<string> */
    private array $items;

    public function __construct(string ...$items)
    {
        $this->items = array_values($items);
    }

    /**
     * @return Traversable<int, string>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}
