<?php

declare(strict_types=1);

namespace Intakt\Core\Lanes\Domain;

use InvalidArgumentException;

final class HeadsInFlightLimitMustBePositive extends InvalidArgumentException
{
    public static function given(int $heads): self
    {
        return new self(sprintf('The limit on heads in flight must allow at least one head, %d given.', $heads));
    }

    public static function twiceTooLarge(int $cap): self
    {
        return new self(sprintf('Twice the review cap of %d heads is not a number of heads PHP can hold.', $cap));
    }
}
