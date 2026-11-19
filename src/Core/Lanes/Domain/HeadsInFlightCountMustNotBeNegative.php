<?php

declare(strict_types=1);

namespace Intakt\Core\Lanes\Domain;

use InvalidArgumentException;

final class HeadsInFlightCountMustNotBeNegative extends InvalidArgumentException
{
    public static function given(int $heads): self
    {
        return new self(sprintf('A number of heads in flight cannot be negative, %d given.', $heads));
    }
}
