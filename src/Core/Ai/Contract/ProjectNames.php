<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

// The names of the configured projects, because module contracts carry typed objects and never lists of strings.
/**
 * @implements IteratorAggregate<int, string>
 */
final readonly class ProjectNames implements IteratorAggregate
{
    /** @var list<string> */
    private array $items;

    public function __construct(string ...$items)
    {
        $this->items = array_values($items);
    }

    public function contains(string $item): bool
    {
        return in_array($item, $this->items, true);
    }

    /**
     * @return Traversable<int, string>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}
