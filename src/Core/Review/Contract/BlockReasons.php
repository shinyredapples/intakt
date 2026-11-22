<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

// Why a round is blocked, in plain words, as one value, because module contracts carry typed objects and never lists of strings.
/**
 * @implements IteratorAggregate<int, string>
 */
final readonly class BlockReasons implements IteratorAggregate
{
    /** @var list<string> */
    private array $reasons;

    public function __construct(string ...$reasons)
    {
        $this->reasons = array_values($reasons);
    }

    public function withFirst(string $reason): self
    {
        return new self($reason, ...$this->reasons);
    }

    /**
     * @return Traversable<int, string>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->reasons);
    }
}
