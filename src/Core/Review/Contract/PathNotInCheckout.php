<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final class PathNotInCheckout extends CheckoutPathRefused
{
    public static function for(string $path): self
    {
        return new self(sprintf('The path "%s" does not exist at the head under review.', $path));
    }
}
