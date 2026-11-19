<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ReviewChange;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Plugin\Vendor\Contract\NoProviderLeft;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Override;
use Symfony\AI\Platform\Exception\RateLimitExceededException;

// One role finds every provider of its tier out, because the real vendor throws this once no account and no substitute is left.
final readonly class OutOfProvidersForRole implements Vendor
{
    public const string REASON = 'Every provider of the tier is out: anthropic for 300 s, openai for 300 s.';

    private VerdictPerRole $others;

    public function __construct(private string $rolePrompt)
    {
        $this->others = new VerdictPerRole([]);
    }

    #[Override]
    public function review(string $project, Tier $tier, ReviewRequest $request): VendorReview
    {
        if ($this->rolePrompt === $request->conversation->rolePrompt) {
            throw new NoProviderLeft(self::REASON, new RateLimitExceededException(300));
        }

        return $this->others->review($project, $tier, $request);
    }

    #[Override]
    public function judge(string $project, JudgeRequest $request): Judgement
    {
        return Judgement::everyBlockingFindingActs($request->findings);
    }

    #[Override]
    public function mapsAModelTo(string $project, Tier $tier): bool
    {
        return true;
    }
}
