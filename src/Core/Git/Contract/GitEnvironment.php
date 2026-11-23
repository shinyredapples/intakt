<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

use ArrayIterator;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

// An unset variable is held as false, because Symfony Process drops an inherited variable only where its value is false.
/**
 * @implements IteratorAggregate<string, string|false>
 */
final readonly class GitEnvironment implements IteratorAggregate
{
    /**
     * @param array<string, string|false> $variables
     */
    private function __construct(private array $variables)
    {
    }

    public static function of(string ...$variables): self
    {
        return new self([])->with(...$variables);
    }

    // In place where the name is already there, because the process gets the variables in this order.
    public function with(string ...$variables): self
    {
        $named = [];
        foreach ($variables as $name => $value) {
            if (!is_string($name)) {
                throw new InvalidArgumentException('A git environment value has no variable name.');
            }

            $named[$name] = $value;
        }

        return new self([...$this->variables, ...$named]);
    }

    public function unsetting(string ...$names): self
    {
        return new self([...$this->variables, ...array_fill_keys($names, false)]);
    }

    /**
     * @return Traversable<string, string|false>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->variables);
    }
}
