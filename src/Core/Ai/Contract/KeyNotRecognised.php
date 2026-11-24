<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use RuntimeException;

// The message names only the listed providers and never carries the input, not even a prefix, so a refused key cannot reach a log.
final class KeyNotRecognised extends RuntimeException
{
    public function __construct(string ...$labels)
    {
        parent::__construct('The key has the form of no listed provider ('.implode(', ', $labels).').');
    }
}
