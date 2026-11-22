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
use Intakt\Core\Review\Contract\ModelNames;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReviewerBlocked;
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

        $starts = $this->startsFor($roundId, $recorded);

        return $this->tokensOfCalls($starts, $this->usagesByCall($recorded, $starts));
    }

    /**
     * @param list<string> $reviewerIds
     *
     * @return array<string, ReviewerFigures>
     */
    // One read of the usage rows and one of the round's rows for all of its reviewers, because a read per reviewer scanned the ledger again for each.
    public function figuresOfReviewers(string $roundId, array $reviewerIds): array
    {
        $recorded = $this->usageRows();
        $starts = $this->startsFor($roundId, $recorded);
        $usages = $this->usagesByCall($recorded, $starts);
        $moments = $this->momentsOfReviewers($roundId);
        $figures = [];

        foreach ($reviewerIds as $reviewerId) {
            $calls = array_filter($starts, static fn (UsageStarted $started): bool => $reviewerId === $started->reviewer);
            $figures[$reviewerId] = new ReviewerFigures(
                $this->tokensOfCalls($calls, $usages),
                $this->millisecondsOf($moments[$reviewerId] ?? ['started' => [], 'ended' => []]),
                $this->modelsOfCalls($calls),
            );
        }

        return $figures;
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
     * @param list<RecordedEvent> $recorded
     *
     * @return array<string, UsageStarted>
     */
    // Keyed by call id, because only the started row names the round, the reviewer and the model, so the settled or unsettled row that carries the tokens is tied to it by its call id.
    private function startsFor(string $roundId, array $recorded): array
    {
        $starts = [];

        foreach ($recorded as $row) {
            $started = $this->payloads->of($row, UsageStarted::class);

            if ('started' === $started?->phase && $roundId === $started->round) {
                $starts[$started->call] = $started;
            }
        }

        return $starts;
    }

    /**
     * @param list<RecordedEvent> $recorded
     *
     * @return array<string, string|null>
     */
    private function callsStartedFor(string $roundId, array $recorded): array
    {
        return array_map(static fn (UsageStarted $started): ?string => $started->model, $this->startsFor($roundId, $recorded));
    }

    /**
     * @param list<RecordedEvent>         $recorded
     * @param array<string, UsageStarted> $calls
     *
     * @return array<string, list<TokenUsageRow>>
     */
    // Only the round's calls are kept, because the ledger holds the usage of every round and the rest would only be held in memory.
    private function usagesByCall(array $recorded, array $calls): array
    {
        $usages = [];

        foreach ($recorded as $row) {
            $ended = $this->endingOf($row);

            if (null !== $ended && array_key_exists($ended->call, $calls)) {
                $usages[$ended->call] = [...$usages[$ended->call] ?? [], ...$ended->usages];
            }
        }

        return $usages;
    }

    /**
     * @param array<string, UsageStarted>        $calls
     * @param array<string, list<TokenUsageRow>> $usages
     */
    private function tokensOfCalls(array $calls, array $usages): RoundTokens
    {
        return RoundTokens::of(count($calls), $this->figuresOf(array_merge([], ...array_values(array_intersect_key($usages, $calls)))));
    }

    /**
     * @param array<string, UsageStarted> $calls
     */
    // Each model once, in the order the reviewer's calls started, because a substitute provider may answer some of its calls; a call started before the model was kept names none.
    private function modelsOfCalls(array $calls): ModelNames
    {
        $models = array_filter(array_map(static fn (UsageStarted $started): ?string => $started->model, $calls), static fn (?string $model): bool => null !== $model);

        return new ModelNames(...array_values(array_unique($models)));
    }

    /**
     * @return array<string, array{started: list<DateTimeImmutable>, ended: list<DateTimeImmutable>}>
     */
    // Read once from the rows that name the round, because every reviewer row carries its round; the round is checked again, because the match is on the payload text.
    private function momentsOfReviewers(string $roundId): array
    {
        $moments = [];

        foreach ($this->ledger->readMentioning([$roundId]) as $recorded) {
            $row = $this->reviewerRowOf($recorded);

            if (null === $row || $roundId !== $row->round) {
                continue;
            }

            $moments[$row->reviewer] ??= ['started' => [], 'ended' => []];
            $moments[$row->reviewer][$row instanceof ReviewerStarted ? 'started' : 'ended'][] = $recorded->recordedAt;
        }

        return $moments;
    }

    // A blocked reviewer has ended too, whatever blocked it, because it will answer no more.
    private function reviewerRowOf(RecordedEvent $recorded): ReviewerStarted|VerdictReceived|ReviewerFailed|ReviewerStopped|ReviewerBlocked|null
    {
        $payload = match ($recorded->event->kind) {
            LedgerEventKind::ReviewerStarted => ReviewerStarted::class,
            LedgerEventKind::VerdictReceived => VerdictReceived::class,
            LedgerEventKind::ReviewerFailed => ReviewerFailed::class,
            LedgerEventKind::ReviewerStopped => ReviewerStopped::class,
            LedgerEventKind::ReviewerBlocked => ReviewerBlocked::class,
            default => null,
        };

        return null === $payload ? null : $this->payloads->of($recorded, $payload);
    }

    /**
     * @param array{started: list<DateTimeImmutable>, ended: list<DateTimeImmutable>} $moments
     */
    // From the reviewer's first start to its last end in the ledger, in milliseconds, because the ledger's clock is the one the workers share; null while it has no end.
    private function millisecondsOf(array $moments): ?int
    {
        if ([] === $moments['started'] || [] === $moments['ended']) {
            return null;
        }

        return (int) max($moments['ended'])->format('Uv') - (int) min($moments['started'])->format('Uv');
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
     * @param list<TokenUsageRow> $usages
     */
    private function figuresOf(array $usages): TokenFigures
    {
        return array_reduce($usages, static fn (TokenFigures $sum, TokenUsageRow $usage): TokenFigures => $sum->plus($usage->figures()), TokenFigures::none());
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
