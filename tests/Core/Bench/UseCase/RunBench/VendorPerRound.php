<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Bench\UseCase\RunBench;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Override;

// Takes the vendors in turn, one per round, so repeated runs of a change differ in a known way instead of by chance.
final class VendorPerRound implements Vendor
{
    /** @var list<string> */
    private array $rounds = [];

    /**
     * @param non-empty-list<Vendor> $vendors
     */
    public function __construct(private readonly array $vendors)
    {
    }

    #[Override]
    public function review(string $project, Tier $tier, ReviewRequest $request): VendorReview
    {
        $round = (string) $request->conversation->round?->toRfc4122();
        if (!in_array($round, $this->rounds, true)) {
            $this->rounds[] = $round;
        }

        $turn = (int) array_search($round, $this->rounds, true);

        return $this->vendors[$turn % count($this->vendors)]->review($project, $tier, $request);
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
