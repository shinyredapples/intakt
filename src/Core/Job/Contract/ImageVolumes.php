<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

// The volumes an image declares, because module contracts carry typed objects and never lists of strings.
/**
 * @implements IteratorAggregate<int, string>
 */
final readonly class ImageVolumes implements IteratorAggregate
{
    /** @var list<string> */
    private array $items;

    public function __construct(string ...$items)
    {
        $this->items = array_values($items);
    }

    public function isEmpty(): bool
    {
        return [] === $this->items;
    }

    /**
     * @return Traversable<int, string>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}
