<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Override;

// The journeys' vendor: the quick one until JOURNEY_SCRIPT names a script directory, then the shipped vendor reviews (its account limit, stop check and streaming) over the scripted Anthropic API of ScriptedHttpClient; the judge stays quick, because no journey scripts it.
final readonly class ScriptedJourneyVendor implements Vendor
{
    public function __construct(
        private JourneyVendor $quick,
        private Vendor $shipped,
        private ?string $script,
    ) {
    }

    #[Override]
    public function review(string $project, Tier $tier, ReviewRequest $request): VendorReview
    {
        return null === $this->script || '' === $this->script ? $this->quick->review($project, $tier, $request) : $this->shipped->review($project, $tier, $request);
    }

    #[Override]
    public function judge(string $project, JudgeRequest $request): Judgement
    {
        return $this->quick->judge($project, $request);
    }

    #[Override]
    public function mapsAModelTo(string $project, Tier $tier): bool
    {
        return $this->quick->mapsAModelTo($project, $tier);
    }
}
