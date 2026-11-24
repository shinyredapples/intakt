<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Contract;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

// The evidence items a pull request text leaves unreferenced, because module contracts carry typed objects and never lists of numbers.
/**
 * @implements IteratorAggregate<int, positive-int>
 */
final readonly class MissingEvidenceItems implements IteratorAggregate
{
    /** @var list<positive-int> */
    private array $items;

    /**
     * @param positive-int ...$items
     */
    public function __construct(int ...$items)
    {
        $this->items = array_values($items);
    }

    public function isEmpty(): bool
    {
        return [] === $this->items;
    }

    public function references(): string
    {
        return implode(', ', array_map(static fn (int $item): string => 'E'.$item, $this->items));
    }

    /**
     * @return Traversable<int, positive-int>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}
