<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Contract;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

// The texts a read looks for in stored payloads as one value, because module contracts carry typed objects and never lists of strings.
/**
 * @implements IteratorAggregate<int, string>
 */
final readonly class Mentions implements IteratorAggregate
{
    /** @var list<string> */
    private array $texts;

    public function __construct(string ...$texts)
    {
        $this->texts = array_values($texts);
    }

    /**
     * @return Traversable<int, string>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->texts);
    }
}
