<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, ChangedFile>
 */
final readonly class ChangedFiles implements IteratorAggregate, Countable
{
    /** @var list<ChangedFile> */
    private array $files;

    public function __construct(ChangedFile ...$files)
    {
        $this->files = array_values($files);
    }

    /**
     * @return Traversable<int, ChangedFile>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->files);
    }

    public function count(): int
    {
        return count($this->files);
    }
}
