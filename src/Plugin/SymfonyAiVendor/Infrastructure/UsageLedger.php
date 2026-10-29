<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use NoDiscard;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageAggregation;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

final readonly class UsageLedger
{
    private const string TOKEN_USAGE = 'token_usage';

    public function __construct(
        private Ledger $ledger,
        private LoggerInterface $logger,
    ) {
    }

    #[NoDiscard('a reservation must be settled or left unsettled')]
    public function reserve(string $project, Tier $tier): UsageReservation
    {
        $reservation = new UsageReservation(Uuid::v7()->toRfc4122());
        $this->record(['call' => $reservation->call, 'phase' => 'started', 'project' => $project, 'tier' => $tier->value]);

        return $reservation;
    }

    public function settle(UsageReservation $reservation, DeferredResult $result): void
    {
        $this->record(['call' => $reservation->call, 'phase' => 'settled', 'usages' => $this->usagesOf($result)]);
    }

    public function leaveUnsettled(UsageReservation $reservation, Throwable $failure): void
    {
        $this->record(['call' => $reservation->call, 'phase' => 'unsettled', 'failure' => $failure::class]);
    }

    /**
     * @return list<array<string, int|string|null>>
     */
    private function usagesOf(DeferredResult $result): array
    {
        $usage = $result->getMetadata()->get(self::TOKEN_USAGE);
        if (!$usage instanceof TokenUsageInterface) {
            return [];
        }

        $usages = $usage instanceof TokenUsageAggregation ? $usage->getTokenUsages() : [$usage];

        return array_values(array_map($this->asRow(...), $usages));
    }

    /**
     * @return array<string, int|string|null>
     */
    private function asRow(TokenUsageInterface $usage): array
    {
        return [
            'model' => $usage->getModel(),
            'prompt' => $usage->getPromptTokens(),
            'completion' => $usage->getCompletionTokens(),
            'thinking' => $usage->getThinkingTokens(),
            'tool' => $usage->getToolTokens(),
            'cached' => $usage->getCachedTokens(),
            'cache_creation' => $usage->getCacheCreationTokens(),
            'cache_read' => $usage->getCacheReadTokens(),
            'total' => $usage->getTotalTokens(),
            'remaining' => $usage->getRemainingTokens(),
            'remaining_minute' => $usage->getRemainingTokensMinute(),
            'remaining_month' => $usage->getRemainingTokensMonth(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function record(array $payload): void
    {
        $appended = $this->ledger->append(new LedgerEvent(LedgerEventKind::UsageRecorded, json_encode($payload, JSON_THROW_ON_ERROR)));
        if ($appended->succeeded()) {
            return;
        }

        // A lost usage row must not lose the verdict the call paid for.
        $this->logger->warning('Token usage was not recorded.', ['call' => $payload['call'], 'phase' => $payload['phase'], 'reason' => $appended->failure]);
    }
}
