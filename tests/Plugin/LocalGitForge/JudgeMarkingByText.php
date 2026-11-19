<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\JudgedFinding;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\Mark;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use Override;

// Answers the judge by a finding's text, because the round gives the findings their ids only after the reviewers answered; a finding it does not name acts without a note.
final readonly class JudgeMarkingByText implements Vendor
{
    /**
     * @param array<string, array{Mark, string}> $marks
     */
    public function __construct(
        private Vendor $reviewers,
        private array $marks,
    ) {
    }

    #[Override]
    public function review(string $project, Tier $tier, ReviewRequest $request): VendorReview
    {
        return $this->reviewers->review($project, $tier, $request);
    }

    #[Override]
    public function judge(string $project, JudgeRequest $request): Judgement
    {
        return new Judgement(array_map($this->marked(...), $request->findings));
    }

    #[Override]
    public function mapsAModelTo(string $project, Tier $tier): bool
    {
        return $this->reviewers->mapsAModelTo($project, $tier);
    }

    private function marked(Finding $finding): JudgedFinding
    {
        [$mark, $note] = $this->marks[$finding->text] ?? [Mark::Act, null];

        return new JudgedFinding($finding->id, $mark, note: $note);
    }
}
