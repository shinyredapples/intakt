<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ReviewChange;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Override;
use Symfony\AI\Platform\Exception\MaxOutputTokensException;

// The vendor of one role stops at the output limit on every call, as the real vendor does after its single retry.
final readonly class StoppingAtTheLimitForRole implements Vendor
{
    private VerdictPerRole $others;

    /**
     * @param array<string, Verdict> $verdicts
     */
    public function __construct(private string $rolePrompt, array $verdicts = [])
    {
        $this->others = new VerdictPerRole($verdicts);
    }

    #[Override]
    public function review(string $project, Tier $tier, ReviewRequest $request): VendorReview
    {
        if ($this->rolePrompt === $request->conversation->rolePrompt) {
            throw new MaxOutputTokensException('Truncated at the output limit.');
        }

        return $this->others->review($project, $tier, $request);
    }

    #[Override]
    public function judge(string $project, JudgeRequest $request): Judgement
    {
        return Judgement::everyBlockingFindingActs($request->findings);
    }
}
