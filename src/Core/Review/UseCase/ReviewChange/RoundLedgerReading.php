<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReviewChange;

use DateTimeImmutable;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Budget\Contract\Prices;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\DroppedFinding;
use Intakt\Core\Review\Contract\JudgeDone;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReviewerFailed;
use Intakt\Core\Review\Contract\ReviewerStarted;
use Intakt\Core\Review\Contract\ReviewerStopped;
use Intakt\Core\Review\Contract\ReviewStarted;
use Intakt\Core\Review\Contract\RoundCosts;
use Intakt\Core\Review\Contract\RoundTokens;
use Intakt\Core\Review\Contract\VerdictReceived;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenUsageRow;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageSettled;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageStarted;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageUnsettled;

// Reads back, because the ledger is where the judge's act ids and the vendor's token usage are recorded.
final readonly class RoundLedgerReading implements RoundCosts
{
    public function __construct(
        private Ledger $ledger,
        private LedgerPayloads $payloads,
        private Prices $prices,
    ) {
    }

    // Null until the judge has answered; the act list is built from the whole record, because its act ids, cause links and notes belong together.
    public function judgeDoneOf(string $roundId): ?JudgeDone
    {
        foreach ($this->ledger->readOfKind(LedgerEventKind::JudgeDone) as $recorded) {
            $judged = $this->payloads->of($recorded, JudgeDone::class);

            if ($roundId === $judged?->round) {
                return $judged;
            }
        }

        return null;
    }

    // All of the round's votes at once, because a read per reviewer would scan the judge events once for each; none for a round judged before votes were counted, so its reviewers vote as their verdicts were given.
    /**
     * @return array<string, Decision>
     */
    public function votesOf(string $roundId): array
    {
        return $this->judgeDoneOf($roundId)->votes ?? [];
    }

    /**
     * @return list<DroppedFinding>
     */
    public function droppedOf(string $roundId): array
    {
        return $this->judgeDoneOf($roundId)->dropped ?? [];
    }

    // Null for a round whose start was recorded before the quorum was, so its outcome cannot be decided.
    public function quorumOf(string $roundId): ?QuorumPolicy
    {
        foreach ($this->ledger->readOfKind(LedgerEventKind::ReviewStarted) as $recorded) {
            $started = $this->payloads->of($recorded, ReviewStarted::class);

            if ($roundId === $started?->round) {
                return $started->quorum;
            }
        }

        return null;
    }

    public function tokensOf(string $roundId): RoundTokens
    {
        $recorded = $this->usageRows();
        $calls = array_keys($this->callsStartedFor($roundId, $recorded));

        $figures = $this->figuresOf($this->usagesOf($calls, $recorded));

        return RoundTokens::of(count($calls), $figures);
    }

    // Only the calls the reviewer made, because the started row names the reviewer and the rows that carry the tokens are tied to it by their call id.
    public function tokensOfReviewer(string $roundId, string $reviewerId): RoundTokens
    {
        $recorded = $this->usageRows();
        $calls = array_keys($this->callsStartedFor($roundId, $recorded, $reviewerId));

        return RoundTokens::of(count($calls), $this->figuresOf($this->usagesOf($calls, $recorded)));
    }

    /**
     * @return list<string>
     */
    // Each model once, in the order the reviewer's calls started, because a substitute provider may answer some of its calls; a call started before the model was kept names none.
    public function modelsOfReviewer(string $roundId, string $reviewerId): array
    {
        $models = array_filter($this->callsStartedFor($roundId, $this->usageRows(), $reviewerId), static fn (?string $model): bool => null !== $model);

        return array_values(array_unique($models));
    }

    // From the reviewer's first start to its last end in the ledger, in milliseconds, because the ledger's clock is the one the workers share; null while it has no end.
    public function millisecondsOfReviewer(string $reviewerId): ?int
    {
        $started = $this->momentsOf(LedgerEventKind::ReviewerStarted, ReviewerStarted::class, $reviewerId);
        $ended = [
            ...$this->momentsOf(LedgerEventKind::VerdictReceived, VerdictReceived::class, $reviewerId),
            ...$this->momentsOf(LedgerEventKind::ReviewerFailed, ReviewerFailed::class, $reviewerId),
            ...$this->momentsOf(LedgerEventKind::ReviewerStopped, ReviewerStopped::class, $reviewerId),
        ];

        if ([] === $started || [] === $ended) {
            return null;
        }

        return (int) max($ended)->format('Uv') - (int) min($started)->format('Uv');
    }

    // Priced per call by the model its tier resolved to, because a round's reviewers and its judge may each run on another model, and the vendor's answer names none (Claude Code, Codex) or a dated snapshot. A call without usages is still priced, because its model's price may be unknown, and that must not read as zero. A usage without input tokens makes the cost unknown, because the output alone would read as the whole cost.
    public function costOf(string $roundId): Cost
    {
        $recorded = $this->usageRows();
        $models = $this->callsStartedFor($roundId, $recorded);

        return array_reduce($recorded, fn (Cost $sum, RecordedEvent $row): Cost => $sum->plus($this->costOfEnding($row, $models)), Cost::none());
    }

    /**
     * @return list<RecordedEvent>
     */
    private function usageRows(): array
    {
        return iterator_to_array($this->ledger->readOfKind(LedgerEventKind::UsageRecorded), false);
    }

    /**
     * @param class-string<ReviewerStarted|VerdictReceived|ReviewerFailed|ReviewerStopped> $payload
     *
     * @return list<DateTimeImmutable>
     */
    private function momentsOf(LedgerEventKind $kind, string $payload, string $reviewerId): array
    {
        $moments = [];

        foreach ($this->ledger->readOfKind($kind) as $recorded) {
            if ($reviewerId === $this->payloads->of($recorded, $payload)?->reviewer) {
                $moments[] = $recorded->recordedAt;
            }
        }

        return $moments;
    }

    /**
     * @param list<RecordedEvent> $recorded
     *
     * @return array<string, string|null>
     */
    // Keyed by call id with the model as value, because only the started row names the round and the model, so the settled or unsettled row that carries the tokens is tied to it by its call id.
    private function callsStartedFor(string $roundId, array $recorded, ?string $reviewerId = null): array
    {
        $calls = [];

        foreach ($recorded as $row) {
            $started = $this->payloads->of($row, UsageStarted::class);

            if ('started' === $started?->phase && $roundId === $started->round && (null === $reviewerId || $reviewerId === $started->reviewer)) {
                $calls[$started->call] = $started->model;
            }
        }

        return $calls;
    }

    /**
     * @param array<string, string|null> $models
     */
    private function costOfEnding(RecordedEvent $row, array $models): Cost
    {
        $ended = $this->endingOf($row);

        if (null === $ended || !array_key_exists($ended->call, $models)) {
            return Cost::none();
        }

        $cost = $this->prices->costOf($models[$ended->call], $this->figuresOf($ended->usages));

        return array_any($ended->usages, static fn (TokenUsageRow $usage): bool => null === $usage->prompt) ? $cost->plus(Cost::unknown()) : $cost;
    }

    /**
     * @param list<string>        $calls
     * @param list<RecordedEvent> $recorded
     *
     * @return list<TokenUsageRow>
     */
    private function usagesOf(array $calls, array $recorded): array
    {
        return array_merge([], ...array_map(fn (RecordedEvent $row): array => $this->usagesEndingOneOf($calls, $row), $recorded));
    }

    /**
     * @param list<TokenUsageRow> $usages
     */
    private function figuresOf(array $usages): TokenFigures
    {
        return array_reduce($usages, static fn (TokenFigures $sum, TokenUsageRow $usage): TokenFigures => $sum->plus($usage->figures()), TokenFigures::none());
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
