<?php

declare(strict_types=1);

namespace Intakt\Plugin\Vendor\Contract;

use RuntimeException;

// The account already has its most parallel calls running, so the caller tries again later instead of waiting for a free place.
final class VendorAccountBusy extends RuntimeException
{
    public static function at(string $account): self
    {
        return new self(sprintf('The vendor account %s has its most parallel calls running.', $account));
    }
}
