<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use InvalidArgumentException;

final readonly class ReviewToken
{
    public function __construct(public string $value)
    {
        // Only characters that cannot close the marker the token is posted in, and long enough not to be guessed.
        if (1 !== preg_match('/^[A-Za-z0-9_-]{16,128}\z/', $value)) {
            throw new InvalidArgumentException('A review token is 16 to 128 letters, digits, "_" or "-".');
        }
    }
}
