<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Plugin\Vendor\Contract\UsageNotRecorded;
use NoDiscard;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageAggregation;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;
use Throwable;

final readonly class UsageLedger
{
    private const string TOKEN_USAGE = 'token_usage';

    public function __construct(
        private Ledger $ledger,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws UsageNotRecorded
     */
    #[NoDiscard('a reservation must be settled or left unsettled')]
    public function reserve(string $project, Tier $tier): UsageReservation
    {
        $reservation = new UsageReservation(UuidV7::generate($this->clock->now()));
        $this->record(['call' => $reservation->call, 'phase' => 'started', 'project' => $project, 'tier' => $tier->value]);

        return $reservation;
    }

    /**
     * @throws UsageNotRecorded
     */
    public function settle(UsageReservation $reservation, DeferredResult $result): void
    {
        $this->record(['call' => $reservation->call, 'phase' => 'settled', 'usages' => $this->usagesOf($result)]);
    }

    /**
     * @throws UsageNotRecorded
     */
    public function leaveUnsettled(UsageReservation $reservation, Throwable $failure): void
    {
        $this->record(['call' => $reservation->call, 'phase' => 'unsettled', 'failure' => $failure::class], $failure);
    }

    /**
     * @return list<array<string, int|string|null>>
     */
    private function usagesOf(DeferredResult $result): array
    {
        $usage = $this->tokenUsageOf($result);
        if (!$usage instanceof TokenUsageInterface) {
            return [];
        }

        $usages = $usage instanceof TokenUsageAggregation ? $usage->getTokenUsages() : [$usage];

        return array_values(array_map($this->asRow(...), $usages));
    }

    // An answer that fails to map leaves no metadata on the deferred result, yet its tokens were spent, so they are read from the raw answer.
    private function tokenUsageOf(DeferredResult $result): ?TokenUsageInterface
    {
        $usage = $result->getMetadata()->get(self::TOKEN_USAGE)
            ?? $result->getResultConverter()->getTokenUsageExtractor()?->extract($result->getRawResult())
            ?? $this->convertedUsageOf($result->getRawResult());

        return $usage instanceof TokenUsageInterface ? $usage : null;
    }

    // Needed because an in-memory platform (MockPlatformFactory) has no extractor and hands over a converted result that carries the usage.
    private function convertedUsageOf(RawResultInterface $raw): mixed
    {
        $converted = $raw->getObject();

        return $converted instanceof ResultInterface ? $converted->getMetadata()->get(self::TOKEN_USAGE) : null;
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
     *
     * @throws UsageNotRecorded
     */
    private function record(array $payload, ?Throwable $failure = null): void
    {
        $usage = json_encode($payload, JSON_THROW_ON_ERROR);
        $appended = $this->ledger->append(new LedgerEvent(LedgerEventKind::UsageRecorded, $usage));
        if ($appended->succeeded()) {
            return;
        }

        // Logged with the full row, because a call that ran must be settled later from its usage, never lost (docs/design/05-architecture/06-budget-and-model-planner.md).
        $this->logger->error('Token usage was not recorded.', ['usage' => $usage, 'reason' => $appended->failure]);

        throw new UsageNotRecorded($usage, (string) $appended->failure, $failure);
    }
}
