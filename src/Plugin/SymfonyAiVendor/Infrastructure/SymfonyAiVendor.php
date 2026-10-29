<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierInvoker;
use Intakt\Core\Ai\Contract\TierRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Throwable;

// Public because no service injects it before the review agent (plan T7), and an unused private service is removed.
#[Autoconfigure(public: true)]
final readonly class SymfonyAiVendor implements Vendor
{
    public function __construct(
        private TierInvoker $tiers,
        private VerdictParser $parser,
        private UsageLedger $usage,
    ) {
    }

    public function review(string $project, Tier $tier, string $task): Verdict
    {
        $request = $task
            |> $this->asMessages(...)
            |> $this->askingForAVerdict(...);
        $reservation = $this->usage->reserve($project, $tier);

        try {
            $result = $this->tiers->invoke($project, $tier, $request);
            $verdict = $this->parser->parse($result);
        } catch (Throwable $failure) {
            $this->usage->leaveUnsettled($reservation, $failure);

            throw $failure;
        }

        $this->usage->settle($reservation, $result);

        return $verdict;
    }

    private function asMessages(string $task): MessageBag
    {
        return new MessageBag(Message::ofUser($task));
    }

    // The class, not the schema file, because structured output derives the schema from it and the Validator checks its constraints (plan T5).
    private function askingForAVerdict(MessageBag $messages): TierRequest
    {
        return new TierRequest($messages, [PlatformSubscriber::RESPONSE_FORMAT => Verdict::class]);
    }
}
