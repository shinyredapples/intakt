<?php

declare(strict_types=1);

namespace Intakt\Plugin\Gate\Contract;

use ArrayIterator;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

// At least one, because an install keyed by no file would be shared by heads that lock different dependencies.
/**
 * @implements IteratorAggregate<int, string>
 */
final readonly class LockFiles implements IteratorAggregate
{
    /** @var list<string> */
    private array $paths;

    public function __construct(string ...$paths)
    {
        if ([] === $paths) {
            throw new InvalidArgumentException('A dependency step names at least one lock file.');
        }

        $this->paths = array_values($paths);
    }

    /**
     * @return Traversable<int, string>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->paths);
    }
}
