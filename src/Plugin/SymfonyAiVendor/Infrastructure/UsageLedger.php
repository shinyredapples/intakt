<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Closure;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenUsageRow;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageSettled;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageStarted;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageUnsettled;
use Intakt\Plugin\Vendor\Contract\UsageNotRecorded;
use NoDiscard;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Exception\ExceptionInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageAggregation;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
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
    public function reserve(string $project, AnsweringTier $tier, ?Uuid $round = null): UsageReservation
    {
        return $this->started(fn (string $call): UsageStarted => new UsageStarted($call, 'started', $project, $tier->tier, $round?->toString(), $tier->answering->model));
    }

    /**
     * @throws UsageNotRecorded
     */
    #[NoDiscard('a reservation must be settled or left unsettled')]
    public function reserveForReviewer(string $project, AnsweringTier $tier, ReviewerConversation $conversation): UsageReservation
    {
        return $this->started(fn (string $call): UsageStarted => new UsageStarted($call, 'started', $project, $tier->tier, $conversation->round?->toString(), $tier->answering->model, $conversation->reviewerId?->toString()));
    }

    /**
     * @throws UsageNotRecorded
     */
    public function settle(UsageReservation $reservation, DeferredResult ...$rounds): void
    {
        ['usages' => $usages, 'unreadable' => $unreadable] = $this->readRounds($reservation, ...$rounds);
        if ($unreadable instanceof Throwable) {
            // Unsettled, not failed, because the answer arrived and its verdict stands; the vendor's usage settles the call later (docs/design/05-architecture/06-budget-and-model-planner.md).
            $this->record(new UsageUnsettled($reservation->call, 'unsettled', $unreadable::class, $usages), $unreadable);

            return;
        }

        $this->record(new UsageSettled($reservation->call, 'settled', $usages));
    }

    /**
     * @throws UsageNotRecorded
     */
    public function leaveUnsettled(UsageReservation $reservation, Throwable $failure, DeferredResult ...$rounds): void
    {
        $usages = $this->readRounds($reservation, ...$rounds)['usages'];
        $this->record(new UsageUnsettled($reservation->call, 'unsettled', $failure::class, $usages), $failure);
    }

    /**
     * @param Closure(string): UsageStarted $row
     *
     * @throws UsageNotRecorded
     */
    private function started(Closure $row): UsageReservation
    {
        $reservation = new UsageReservation(UuidV7::generate($this->clock->now()));
        $this->record($row($reservation->call));

        return $reservation;
    }

    /**
     * @return array{usages: list<TokenUsageRow>, unreadable: ?Throwable}
     */
    // Each round is read once, because reading can run the provider's extractor again; the rounds that answered spent their tokens, so the row keeps them, and a round that cannot be read is logged and left out on its own, so it does not hide the others.
    private function readRounds(UsageReservation $reservation, DeferredResult ...$rounds): array
    {
        $usages = [];
        $firstUnreadable = null;
        foreach (array_values($rounds) as $index => $round) {
            try {
                $usages[] = $this->usagesOf($round);
                // Boundary: the catch below logs the unreadable round and goes on with the others, because unreadable usage must not lose the call or its other rounds from the ledger.
            } catch (Throwable $unreadable) {
                $this->logger->warning('Token usage of a round could not be read.', ['call' => $reservation->call, 'round' => $index, 'exception' => $unreadable]);
                $firstUnreadable ??= $unreadable;
            }
        }

        return ['usages' => array_merge(...$usages), 'unreadable' => $firstUnreadable];
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

    // An answer that fails to map leaves no metadata on the deferred result, yet its tokens were spent, so they are read from the raw answer.
    private function tokenUsageOf(DeferredResult $result): ?TokenUsageInterface
    {
        $usage = $this->streamedUsageOf($result)
            ?? $result->getMetadata()->get(self::TOKEN_USAGE)
            ?? $result->getResultConverter()->getTokenUsageExtractor()?->extract($result->getRawResult())
            ?? $this->convertedUsageOf($result->getRawResult());

        return $usage instanceof TokenUsageInterface ? $usage : null;
    }

    // Each row names the account its round ran on, because a reading belongs to its account (docs/design/05-architecture/06-budget-and-model-planner.md).
    private function accountOf(DeferredResult $result): ?string
    {
        $account = $result->getMetadata()->get(Account::METADATA);

        return is_string($account) ? $account : null;
    }

    // A stream carries the last usage it reported on its own metadata, which the deferred result and the extractor do not see, so it is read first; a result that fails to convert has no stream.
    private function streamedUsageOf(DeferredResult $result): mixed
    {
        try {
            $converted = $result->getResult();
        } catch (ExceptionInterface|HttpClientExceptionInterface) {
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
        $usage = $this->serializer->serialize($payload, JsonEncoder::FORMAT);
        $appended = $this->ledger->append(new LedgerEvent(LedgerEventKind::UsageRecorded, $usage));
        if ($appended->succeeded()) {
            return;
        }

        // Logged with the full row, because a call that ran must be settled later from its usage, never lost (docs/design/05-architecture/06-budget-and-model-planner.md).
        $this->logger->error('Token usage was not recorded.', ['usage' => $usage, 'reason' => $appended->failure]);

        throw new UsageNotRecorded($usage, (string) $appended->failure, $failure);
    }
}
