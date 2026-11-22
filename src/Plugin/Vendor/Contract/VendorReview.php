<?php

declare(strict_types=1);

namespace Intakt\Plugin\Vendor\Contract;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Review\Contract\Verdict;

final readonly class VendorReview
{
    // The substitute is set only when another model than the tier's own answered (another provider, or another model on the own provider), because that owes the reviewer a catch-up review; its model goes with it, because the ledger cannot tell which of a provider's models answered.
    public function __construct(
        public Verdict $verdict,
        public ?Provider $substitute = null,
        public ?string $substituteModel = null,
    ) {
    }
}
