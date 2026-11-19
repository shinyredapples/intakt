<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Ai\Contract\ResolvedModel;
use NoDiscard;

// The interface exists because Plugin/SymfonyAiVendor may use only the module's Contract; the vendor calls it for a request that names a review job.
interface JobReviewer
{
    /**
     * @throws VerdictUnreadable
     */
    #[NoDiscard('a verdict that is not read approves nothing')]
    public function review(ReviewRequest $request, JobReview $job, ResolvedModel $model): JobVerdict;
}
