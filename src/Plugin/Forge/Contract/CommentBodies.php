<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

// The bodies of comments a forge posts in this order, because module contracts carry typed objects and never lists of strings.
/**
 * @implements IteratorAggregate<int, string>
 */
final readonly class CommentBodies implements IteratorAggregate
{
    /** @var list<string> */
    private array $bodies;

    public function __construct(string ...$bodies)
    {
        $this->bodies = array_values($bodies);
    }

    /**
     * @return Traversable<int, string>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->bodies);
    }
}
