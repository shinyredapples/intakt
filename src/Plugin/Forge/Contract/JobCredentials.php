<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use ArrayIterator;
use InvalidArgumentException;
use IteratorAggregate;
use SensitiveParameter;
use Traversable;

// The values a job could have copied into its text, so the forge can redact them before anything is posted.
/**
 * @implements IteratorAggregate<int, string>
 */
final readonly class JobCredentials implements IteratorAggregate
{
    /** @var list<string> */
    private array $values;

    public function __construct(#[SensitiveParameter] string ...$values)
    {
        foreach ($values as $value) {
            if ('' === $value) {
                throw new InvalidArgumentException('A credential has a value.');
            }
        }

        $this->values = array_values($values);
    }

    /**
     * @return Traversable<int, string>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->values);
    }
}
