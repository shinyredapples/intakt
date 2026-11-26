<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use InvalidArgumentException;

// One home for the default, because the config tree and the typed value must agree on it.
final readonly class ReviewSizeLimit
{
    public const int DEFAULT_CHARACTERS = 200000;

    public function __construct(public int $characters)
    {
        if ($characters < 1) {
            throw new InvalidArgumentException('A review size limit is a positive number of characters.');
        }
    }

    public static function default(): self
    {
        return new self(self::DEFAULT_CHARACTERS);
    }
}
