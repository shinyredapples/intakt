<?php

declare(strict_types=1);

namespace Intakt\Plugin\Vendor\Contract;

use Intakt\Core\Build\Contract\SizingRequest;
use Intakt\Core\Build\Contract\SizingVerdict;
use NoDiscard;

// Apart from Vendor, because only the sizing call needs it and every other implementation of Vendor would have to stub it.
interface SizingVendor
{
    // Null when the model's answer is no valid verdict, because an invalid answer must not park a task; its tokens are charged to the task either way.
    /**
     * @throws UsageNotRecorded
     * @throws ProviderRateLimited
     * @throws ProviderFailedTransiently
     * @throws VendorAccountOut
     * @throws VendorAccountBusy
     * @throws NoProviderLeft
     * @throws ProviderRejectedTheKey
     * @throws ProviderUnreachable
     */
    #[NoDiscard('a verdict that is not read sizes nothing')]
    public function size(string $project, SizingRequest $request): ?SizingVerdict;
}
