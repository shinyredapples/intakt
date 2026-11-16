<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ReviewChange;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Plugin\Vendor\Contract\ProviderUnreachable;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Override;
use Symfony\Component\HttpClient\Exception\TransportException;

// One role cannot reach its endpoint on any call, because the real vendor throws this once its single retry could not reach it either.
final readonly class UnreachableForRole implements Vendor
{
    private VerdictPerRole $others;

    public function __construct(private string $rolePrompt)
    {
        $this->others = new VerdictPerRole([]);
    }

    #[Override]
    public function review(string $project, Tier $tier, ReviewRequest $request): VendorReview
    {
        if ($this->rolePrompt === $request->conversation->rolePrompt) {
            throw new ProviderUnreachable(new TransportException('Could not resolve host: localhost'));
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
