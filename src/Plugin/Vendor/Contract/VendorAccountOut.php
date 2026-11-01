<?php

declare(strict_types=1);

namespace Intakt\Plugin\Vendor\Contract;

use RuntimeException;
use Throwable;

// The account the review ran on is cooling down while another account of its vendor is free, so the review starts again there at once and is never continued by another vendor.
final class VendorAccountOut extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('The review\'s account is cooling down; another account of its vendor is free.', previous: $previous);
    }
}
