<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use DomainException;

final class PathOutsideCheckout extends DomainException
{
    public static function for(string $path): self
    {
        return new self(sprintf('The path "%s" is outside the repository under review.', $path));
    }
}
