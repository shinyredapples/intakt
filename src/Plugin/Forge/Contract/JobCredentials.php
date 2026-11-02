<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use InvalidArgumentException;
use SensitiveParameter;

// The values a job could have copied into its text, so the forge can redact them before anything is posted.
final readonly class JobCredentials
{
    /** @var list<string> */
    public array $values;

    public function __construct(#[SensitiveParameter] string ...$values)
    {
        foreach ($values as $value) {
            if ('' === $value) {
                throw new InvalidArgumentException('A credential has a value.');
            }
        }

        $this->values = array_values($values);
    }
}
