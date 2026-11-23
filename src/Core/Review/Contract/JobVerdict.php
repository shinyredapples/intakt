<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// What a review job hands the vendor beside the verdict, because the vendor decides whether an approve counts and settles the usage, and no platform call of its own shows either for a job.
#[Exclude]
final readonly class JobVerdict
{
    /**
     * @param list<TokenUsageInterface> $usage
     */
    public function __construct(
        public Verdict $verdict,
        // From the job CLI's own events, because the model's text could claim a read it never made.
        public bool $readTheCheckout,
        // Empty when the CLI reported none, so the vendor can leave the call unsettled instead of settling zero.
        public array $usage,
    ) {
    }
}
