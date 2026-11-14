<?php

declare(strict_types=1);

namespace Intakt\Plugin\Vendor\Contract;

use Intakt\Core\Ai\Contract\ProjectNotConfigured;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\ChangesWithoutFindings;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\ReviewRequest;
use NoDiscard;

interface Vendor
{
    // The light tier, because sorting findings is a small job; one home, so the check before a round asks for the tier the judge calls.
    public const Tier JUDGE_TIER = Tier::Light;

    /**
     * @throws UsageNotRecorded
     * @throws ProviderRateLimited
     * @throws ProviderFailedTransiently
     * @throws VendorAccountOut
     * @throws NoProviderLeft
     * @throws ProviderRejectedTheKey
     * @throws ChangesWithoutFindings
     */
    #[NoDiscard('a verdict that is not read approves nothing')]
    public function review(string $project, Tier $tier, ReviewRequest $request): VendorReview;

    /**
     * @throws UsageNotRecorded
     * @throws ProviderRateLimited
     * @throws ProviderFailedTransiently
     * @throws VendorAccountOut
     * @throws NoProviderLeft
     * @throws ProviderRejectedTheKey
     */
    #[NoDiscard('a judgement that is not read decides nothing')]
    public function judge(string $project, JudgeRequest $request): Judgement;

    // Asked before a round starts, so a tier the project maps no model to refuses the round instead of failing a call later.
    /**
     * @throws ProjectNotConfigured
     */
    public function mapsAModelTo(string $project, Tier $tier): bool;
}
