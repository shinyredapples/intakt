<?php

declare(strict_types=1);

namespace Intakt\Core\Lanes\Domain;

use InvalidArgumentException;

final class ReviewCapMustBePositive extends InvalidArgumentException
{
    public static function given(int $heads): self
    {
        return new self(sprintf('The review cap must allow at least one head, %d given.', $heads));
    }
}
