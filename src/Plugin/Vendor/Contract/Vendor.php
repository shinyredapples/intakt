<?php

declare(strict_types=1);

namespace Intakt\Plugin\Vendor\Contract;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\ReviewRequest;
use NoDiscard;

interface Vendor
{
    /**
     * @throws UsageNotRecorded
     * @throws ProviderRateLimited
     * @throws ProviderFailedTransiently
     * @throws VendorAccountOut
     * @throws NoProviderLeft
     * @throws ProviderRejectedTheKey
     * @throws ProviderRefusedTheAccount
     */
    #[NoDiscard('a verdict that is not read approves nothing')]
    public function review(string $project, Tier $tier, ReviewRequest $request): VendorReview;

    // Always the light tier, because sorting findings is a small job.
    /**
     * @throws UsageNotRecorded
     * @throws ProviderRateLimited
     * @throws ProviderFailedTransiently
     * @throws VendorAccountOut
     * @throws NoProviderLeft
     * @throws ProviderRejectedTheKey
     * @throws ProviderRefusedTheAccount
     */
    #[NoDiscard('a judgement that is not read decides nothing')]
    public function judge(string $project, JudgeRequest $request): Judgement;
}
