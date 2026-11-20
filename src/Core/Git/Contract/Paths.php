<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

// Repository-relative paths as one value, because module contracts carry typed objects and never lists of strings.
/**
 * @implements IteratorAggregate<int, string>
 */
final readonly class Paths implements IteratorAggregate
{
    /** @var list<string> */
    private array $paths;

    public function __construct(string ...$paths)
    {
        $this->paths = array_values($paths);
    }

    public function sharesAnyWith(self $other): bool
    {
        return [] !== array_intersect($this->paths, $other->paths);
    }

    public function isEmpty(): bool
    {
        return [] === $this->paths;
    }

    /**
     * @return Traversable<int, string>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->paths);
    }
}
