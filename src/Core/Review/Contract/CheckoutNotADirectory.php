<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use InvalidArgumentException;

final class CheckoutNotADirectory extends InvalidArgumentException
{
    public static function create(): self
    {
        return new self('The checkout is not a directory.');
    }
}
