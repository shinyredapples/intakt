<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Ai\Contract\Account;
use RuntimeException;
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
        // The refusal of an answer that is no verdict, or the failure of a CLI that ran, is kept here instead of thrown, so the usage the job reported, or that it reported none, is recorded all the same.
        private Verdict|RuntimeException $answer,
        // From the job CLI's own events and the head's tree, because the model's text could claim a read it never made and a link could lead out of the checkout.
        public bool $readTheCheckout,
        // Empty when the CLI reported none, so the vendor can leave the call unsettled instead of settling zero.
        public array $usage,
        public ?Account $account = null,
    ) {
    }

    /**
     * @throws RuntimeException
     */
    public function verdict(): Verdict
    {
        return $this->answer instanceof Verdict ? $this->answer : throw $this->answer;
    }
}
