<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReviewChange;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\JudgeDone;
use Intakt\Core\Review\Contract\RoundTokens;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenUsageRow;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageSettled;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageStarted;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageUnsettled;

// Reads back, because the ledger is where the judge's act ids and the vendor's token usage are recorded.
final readonly class RoundLedgerReading
{
    public function __construct(
        private Ledger $ledger,
        private LedgerPayloads $payloads,
    ) {
    }

    /**
     * @return list<string>
     */
    public function actIdsOf(string $roundId): array
    {
        foreach ($this->ledger->readOfKind(LedgerEventKind::JudgeDone) as $recorded) {
            $judged = $this->payloads->of($recorded, JudgeDone::class);

            if ($roundId === $judged?->round) {
                return $judged->act;
            }
        }

        return [];
    }

    public function tokensOf(string $roundId): RoundTokens
    {
        $recorded = iterator_to_array($this->ledger->readOfKind(LedgerEventKind::UsageRecorded), false);
        $calls = $this->callsStartedFor($roundId, $recorded);
        $usages = array_merge([], ...array_map(fn (RecordedEvent $row): array => $this->usagesEndingOneOf($calls, $row), $recorded));

        $figures = array_reduce($usages, static fn (TokenFigures $sum, TokenUsageRow $usage): TokenFigures => $sum->plus($usage->figures()), TokenFigures::none());

        return RoundTokens::of(count($calls), $figures);
    }

    /**
     * @param list<RecordedEvent> $recorded
     *
     * @return list<string>
     */
    // Only the started row names the round, so the settled or unsettled row that carries the tokens is tied to it by its call id.
    private function callsStartedFor(string $roundId, array $recorded): array
    {
        $calls = [];

        foreach ($recorded as $row) {
            $started = $this->payloads->of($row, UsageStarted::class);

            if ('started' === $started?->phase && $roundId === $started->round) {
                $calls[] = $started->call;
            }
        }

        return array_values(array_unique($calls));
    }

    /**
     * @param list<string> $calls
     *
     * @return list<TokenUsageRow>
     */
    private function usagesEndingOneOf(array $calls, RecordedEvent $row): array
    {
        $ended = $this->endingOf($row);

        return null !== $ended && in_array($ended->call, $calls, true) ? $ended->usages : [];
    }

    // Tried as settled first, because an unsettled row also reads into UsageSettled and only its phase tells the two apart.
    private function endingOf(RecordedEvent $row): UsageSettled|UsageUnsettled|null
    {
        $settled = $this->payloads->of($row, UsageSettled::class);

        if ('settled' === $settled?->phase) {
            return $settled;
        }

        $unsettled = $this->payloads->of($row, UsageUnsettled::class);

        return 'unsettled' === $unsettled?->phase ? $unsettled : null;
    }
}
