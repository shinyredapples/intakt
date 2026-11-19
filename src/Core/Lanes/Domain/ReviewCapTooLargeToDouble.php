<?php

declare(strict_types=1);

namespace Intakt\Core\Lanes\Domain;

use InvalidArgumentException;

final class ReviewCapTooLargeToDouble extends InvalidArgumentException
{
    public static function given(int $cap): self
    {
        return new self(sprintf('Twice the review cap of %d heads is not a number of heads PHP can hold.', $cap));
    }
}
