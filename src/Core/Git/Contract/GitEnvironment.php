<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

// The variables of one git call by name, where false unsets an inherited one, as Symfony Process reads them.
/**
 * @implements IteratorAggregate<string, string|false>
 */
final readonly class GitEnvironment implements IteratorAggregate
{
    /** @var array<string, string|false> */
    private array $variables;

    public function __construct(string|false ...$variables)
    {
        $this->variables = $variables;
    }

    // A variable given here replaces one of the same name.
    public function with(string|false ...$variables): self
    {
        return new self(...[...$this->variables, ...$variables]);
    }

    /**
     * @return Traversable<string, string|false>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->variables);
    }
}
