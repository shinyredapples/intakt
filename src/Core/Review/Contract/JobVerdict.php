<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

// What a review job hands the vendor beside the verdict, because the vendor decides whether an approve counts and settles the usage, and no platform call of its own shows either for a job.
final readonly class JobVerdict
{
    /**
     * @param list<TokenUsageInterface> $usage
     */
    public function __construct(
        public Verdict $verdict,
        // Read from the job CLI's own events, never from the model's text.
        public bool $readTheCheckout,
        // Empty when the CLI reported no usage.
        public array $usage,
    ) {
    }
}
