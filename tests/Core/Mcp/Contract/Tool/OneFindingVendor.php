<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Mcp\Contract\Tool;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Override;

// Every reviewer asks for one change and the judge keeps it, so a started round ends with a known act list and no model is called.
final readonly class OneFindingVendor implements Vendor
{
    #[Override]
    public function review(string $project, Tier $tier, ReviewRequest $request): VendorReview
    {
        $finding = new Finding('A', Severity::P1, 'src/Feature.php', 3, null, 'Missing check.', FixableBy::Builder, false, null, null);

        return new VendorReview(new Verdict(Decision::Changes, [$finding], [], []));
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
