<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Mcp\Contract\Tool;

use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\ChangeReviewRequest;
use Intakt\Core\Review\Contract\ChangeReviews;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\IgnoredProgress;
use Intakt\Core\Review\Contract\ReviewProgress;
use Intakt\Core\Review\Contract\RoundNotNext;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\RoundTokens;
use Intakt\Core\Review\Contract\Severity;

// Answers like a round that one reviewer finishes and the judge reads, so the tests need no workers.
final readonly class ScriptedChangeReviews implements ChangeReviews
{
    public const string ROUND_ID = '0192a000-0000-7000-8000-000000000001';

    public function review(ChangeReviewRequest $request, ReviewProgress $progress = new IgnoredProgress()): ChangeReview
    {
        if (2 === $request->round->number) {
            throw RoundNotNext::for(2, 1);
        }

        $progress->reached(new RoundProgress(0, 1, false));
        $progress->reached(new RoundProgress(1, 1, false));
        $progress->reached(new RoundProgress(1, 1, true));
        $finding = new Finding('F1', Severity::P1, 'src/Feature.php', 3, null, 'Missing check.', FixableBy::Builder, false, null, null);

        return new ChangeReview(self::ROUND_ID, 1, Decision::Changes, [], [$finding], new RoundTokens(1, 10, 2, 12));
    }
}
