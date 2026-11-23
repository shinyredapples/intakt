<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Closure;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Override;

// The test environment's vendor: with a script directory it is SymfonyAiVendor itself, not AccountLimitedVendor, so a concurrent consumer's reviews take the account cap's places while ScriptedHttpClient answers for the provider; without one it answers as JourneyVendor, so the other journeys still need no key.
final readonly class ScriptedJourneyVendor implements Vendor
{
    /**
     * @param Closure(): Vendor $symfonyAi
     */
    public function __construct(
        private Closure $symfonyAi,
        private JourneyVendor $unscripted,
        private string $scripts,
    ) {
    }

    #[Override]
    public function review(string $project, Tier $tier, ReviewRequest $request): VendorReview
    {
        return $this->vendor()->review($project, $tier, $request);
    }

    #[Override]
    public function judge(string $project, JudgeRequest $request): Judgement
    {
        return $this->vendor()->judge($project, $request);
    }

    #[Override]
    public function mapsAModelTo(string $project, Tier $tier): bool
    {
        return $this->vendor()->mapsAModelTo($project, $tier);
    }

    private function vendor(): Vendor
    {
        return '' === $this->scripts ? $this->unscripted : ($this->symfonyAi)();
    }
}
