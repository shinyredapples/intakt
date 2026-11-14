<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\ReviewChange;

use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\RoundTokens;

// Holds only what differs from the review: the act list rows are the findings themselves, so there is no second copy of a finding.
final readonly class ReviewOutput
{
    // The serializer's attributes context: an act list row keeps the fields docs/user/review-output.schema.json documents, because a finding also carries fields only the loop reads.
    public const array FIELDS = [
        'roundId',
        'round',
        'decision',
        'exitCode',
        'verdicts',
        'actList' => ['id', 'severity', 'file', 'line', 'symbol', 'text', 'fixable_by', 'merged'],
        'tokens',
        'cost',
        'reasons',
    ];

    /**
     * @param list<ReviewOutputVerdict> $verdicts
     * @param list<Finding>             $actList
     * @param list<string>              $reasons
     */
    public function __construct(
        public string $roundId,
        public int $round,
        public Decision $decision,
        public ReviewExit $exitCode,
        public array $verdicts,
        public array $actList,
        public RoundTokens $tokens,
        public Cost $cost,
        public array $reasons,
    ) {
    }

    public static function of(ChangeReview $review): self
    {
        return new self(
            $review->roundId,
            $review->round,
            $review->decision,
            ReviewExit::of($review->decision),
            array_map(ReviewOutputVerdict::of(...), $review->verdicts),
            $review->actList,
            $review->tokens,
            $review->cost,
            $review->reasons,
        );
    }
}
