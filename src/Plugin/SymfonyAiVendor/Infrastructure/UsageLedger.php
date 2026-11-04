<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
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
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageAggregation;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Throwable;

final readonly class UsageLedger
{
    private const string TOKEN_USAGE = 'token_usage';

    public function __construct(
        private Ledger $ledger,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        #[Target('stored')]
        private SerializerInterface $serializer,
    ) {
    }

    /**
     * @throws UsageNotRecorded
     */
    #[NoDiscard('a reservation must be settled or left unsettled')]
    public function reserve(string $project, Tier $tier, ?Uuid $round = null): UsageReservation
    {
        $reservation = new UsageReservation(UuidV7::generate($this->clock->now()));
        $this->record(new UsageStarted($reservation->call, 'started', $project, $tier, $round?->toString()));

        return $reservation;
    }

    /**
     * @throws UsageNotRecorded
     */
    public function settle(UsageReservation $reservation, DeferredResult ...$rounds): void
    {
        try {
            $usages = $this->usagesOfRounds(...$rounds);
        } catch (Throwable $unreadable) {
            $this->leaveUnreadableUnsettled($reservation, $unreadable);

            return;
        }

        $this->record(new UsageSettled($reservation->call, 'settled', $usages));
    }

    /**
     * @throws UsageNotRecorded
     */
    public function leaveUnsettled(UsageReservation $reservation, Throwable $failure, DeferredResult ...$rounds): void
    {
        $usages = $this->usagesBeforeFailure($reservation, array_values($rounds));
        $this->record(new UsageUnsettled($reservation->call, 'unsettled', $failure::class, $usages), $failure);
    }

    /**
     * @param list<DeferredResult> $rounds
     *
     * @return list<TokenUsageRow>
     */
    // The rounds that answered spent their tokens, so the row keeps them; when they cannot be read, the failure is still what the row is for, so it is recorded without them.
    private function usagesBeforeFailure(UsageReservation $reservation, array $rounds): array
    {
        try {
            return $this->usagesOfRounds(...$rounds);
        } catch (Throwable $unreadable) {
            $this->logger->warning('Token usage of the rounds before a failed call could not be read.', ['call' => $reservation->call, 'exception' => $unreadable]);

            return [];
        }
    }

    /**
     * @throws UsageNotRecorded
     */
    private function leaveUnreadableUnsettled(UsageReservation $reservation, Throwable $unreadable): void
    {
        // Unsettled, not failed, because the answer arrived and its verdict stands; the vendor's usage settles the call later (docs/design/05-architecture/06-budget-and-model-planner.md).
        $this->logger->warning('Token usage of an answered call could not be read.', ['call' => $reservation->call, 'exception' => $unreadable]);
        $this->leaveUnsettled($reservation, $unreadable);
    }

    /**
     * @return list<TokenUsageRow>
     */
    private function usagesOfRounds(DeferredResult ...$rounds): array
    {
        return array_merge(...array_map($this->usagesOf(...), $rounds));
    }

    /**
     * @return list<TokenUsageRow>
     */
    private function usagesOf(DeferredResult $result): array
    {
        $usage = $this->tokenUsageOf($result);
        if (!$usage instanceof TokenUsageInterface) {
            return [];
        }

        $usages = $usage instanceof TokenUsageAggregation ? $usage->getTokenUsages() : [$usage];
        $account = $this->accountOf($result);

        return array_values(array_map(fn (TokenUsageInterface $part): TokenUsageRow => $this->asRow($part, $account), $usages));
    }

    // Each row names the account its round ran on, because a reading belongs to its account (docs/design/05-architecture/06-budget-and-model-planner.md).
    private function accountOf(DeferredResult $result): ?string
    {
        $account = $result->getMetadata()->get(Account::METADATA);

        return is_string($account) ? $account : null;
    }

    // An answer that fails to map leaves no metadata on the deferred result, yet its tokens were spent, so they are read from the raw answer.
    private function tokenUsageOf(DeferredResult $result): ?TokenUsageInterface
    {
        $usage = $this->streamedUsageOf($result)
            ?? $result->getMetadata()->get(self::TOKEN_USAGE)
            ?? $result->getResultConverter()->getTokenUsageExtractor()?->extract($result->getRawResult())
            ?? $this->convertedUsageOf($result->getRawResult());

        return $usage instanceof TokenUsageInterface ? $usage : null;
    }

    // A stream carries the last usage it reported on its own metadata, which the deferred result and the extractor do not see, so it is read first; a result that fails to convert has no stream.
    private function streamedUsageOf(DeferredResult $result): mixed
    {
        try {
            $converted = $result->getResult();
        } catch (Throwable) {
            return null;
        }

        return $converted instanceof StreamResult ? $converted->getMetadata()->get(self::TOKEN_USAGE) : null;
    }

    // Needed because an in-memory platform (MockPlatformFactory) has no extractor and hands over a converted result that carries the usage.
    private function convertedUsageOf(RawResultInterface $raw): mixed
    {
        $converted = $raw->getObject();

        return $converted instanceof ResultInterface ? $converted->getMetadata()->get(self::TOKEN_USAGE) : null;
    }

    private function asRow(TokenUsageInterface $usage, ?string $account): TokenUsageRow
    {
        return new TokenUsageRow(
            $account,
            $usage->getModel(),
            $usage->getPromptTokens(),
            $usage->getCompletionTokens(),
            $usage->getThinkingTokens(),
            $usage->getToolTokens(),
            $usage->getCachedTokens(),
            $usage->getCacheCreationTokens(),
            $usage->getCacheReadTokens(),
            $usage->getTotalTokens(),
            $usage->getRemainingTokens(),
            $usage->getRemainingTokensMinute(),
            $usage->getRemainingTokensMonth(),
        );
    }

    /**
     * @throws UsageNotRecorded
     */
    private function record(UsageStarted|UsageSettled|UsageUnsettled $payload, ?Throwable $failure = null): void
    {
        $usage = $this->serializer->serialize($payload, 'json');
        $appended = $this->ledger->append(new LedgerEvent(LedgerEventKind::UsageRecorded, $usage));
        if ($appended->succeeded()) {
            return;
        }

        // Logged with the full row, because a call that ran must be settled later from its usage, never lost (docs/design/05-architecture/06-budget-and-model-planner.md).
        $this->logger->error('Token usage was not recorded.', ['usage' => $usage, 'reason' => $appended->failure]);

        throw new UsageNotRecorded($usage, (string) $appended->failure, $failure);
    }
}
