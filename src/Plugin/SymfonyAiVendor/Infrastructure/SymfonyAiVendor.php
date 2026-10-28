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

final readonly class SymfonyAiVendor implements Vendor
{
    public function __construct(
        private TierInvoker $tiers,
        private VerdictParser $parser,
    ) {
    }

    public function review(string $project, Tier $tier, string $task): Verdict
    {
        $request = $task
            |> $this->asMessages(...)
            |> $this->askingForAVerdict(...);

        return $this->parser->parse($this->tiers->invoke($project, $tier, $request));
    }

    private function asMessages(string $task): MessageBag
    {
        return new MessageBag(Message::ofUser($task));
    }

    // The class, not the schema file: structured output derives the schema from it and the Validator checks the constraints (plan T5).
    private function askingForAVerdict(MessageBag $messages): TierRequest
    {
        return new TierRequest($messages, [PlatformSubscriber::RESPONSE_FORMAT => Verdict::class]);
    }
}
