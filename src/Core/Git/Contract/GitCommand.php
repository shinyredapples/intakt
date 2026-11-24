<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, string>
 */
final readonly class GitCommand implements IteratorAggregate
{
    /** @var list<string> */
    private array $parts;

    public function __construct(string ...$parts)
    {
        $this->parts = array_values($parts);
    }

    /**
     * @return Traversable<int, string>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->parts);
    }
}
