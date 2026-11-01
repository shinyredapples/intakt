<?php

declare(strict_types=1);

namespace Intakt\Plugin\Vendor\Contract;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Review\Contract\Verdict;

final readonly class VendorReview
{
    // The substitute is set only when another provider than the tier's own answered, because that owes the reviewer a catch-up review.
    public function __construct(
        public Verdict $verdict,
        public ?Provider $substitute = null,
    ) {
    }
}
