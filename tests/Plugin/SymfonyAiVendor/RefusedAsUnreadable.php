<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Closure;
use Intakt\Core\Review\Contract\VerdictUnreadable;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use PHPUnit\Framework\Assert;

// Runs a review that must be refused, so a test can still read the usage rows the refused calls left.
final class RefusedAsUnreadable
{
    /**
     * @param Closure(): VendorReview $review
     */
    public static function after(Closure $review): void
    {
        try {
            $review();
        } catch (VerdictUnreadable) {
            return;
        }

        Assert::fail('The answer was not refused as unreadable.');
    }
}
