<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\EscalateRecurringP1;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\ChangeEscalations;
use Intakt\Core\Review\Contract\EscalationOutcome;
use Intakt\Core\Review\Contract\EscalationReviewed;
use Intakt\Core\Review\Contract\EscalationVerdict;
use Intakt\Core\Review\Contract\EscalationVerdictReused;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Domain\KeptVerdict;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Infrastructure\KeptVerdicts;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Override;

// Read from the ledger by plain code, a reused verdict counting as the review it stands in for, so a model's answer reaches the next build or round only through a kept verdict.
final readonly class EscalationsOfChange implements ChangeEscalations
{
    private const array KINDS = [LedgerEventKind::EscalationReviewed->value => EscalationReviewed::class, LedgerEventKind::EscalationVerdictReused->value => EscalationVerdictReused::class];

    public function __construct(
        private ReviewRounds $rounds,
        private StoredFindings $findings,
        private KeptVerdicts $verdicts,
        private Ledger $ledger,
        private LedgerPayloads $payloads,
    ) {
    }

    #[Override]
    public function outcomeOfLatestRound(string $project, string $change): ?EscalationOutcome
    {
        $latest = $this->rounds->latestOfChange($project, $change);

        if (!$latest instanceof ReviewRound) {
            return null;
        }

        $outcomes = array_map(static fn (EscalationReviewed|EscalationVerdictReused $escalation): EscalationOutcome => $escalation->outcome, $this->escalationsOf([$latest->id()->toRfc4122() => true]));

        return in_array(EscalationOutcome::Confirmed, $outcomes, true) ? EscalationOutcome::Confirmed : array_first($outcomes);
    }

    // The kept verdict of each open finding an earlier round of the change escalated, by finding id, because the ids stay the same across rounds and the reviewer that still holds a finding must see why it was confirmed or rejected.
    /**
     * @return array<string, EscalationVerdict>
     */
    public function ofOpenFindings(ReviewRound $round): array
    {
        $open = array_map(static fn (Finding $finding): string => $finding->id, $this->findings->openFor($round));

        if ([] === $open) {
            return [];
        }

        $keys = $this->keysOf($this->earlierRoundsOf($round), $open);
        $verdicts = [];

        foreach ($keys as $finding => $key) {
            $kept = $this->verdicts->ofKey($key);

            if ($kept instanceof KeptVerdict) {
                $verdicts[$finding] = new EscalationVerdict($kept->outcome, $kept->reasons);
            }
        }

        return $verdicts;
    }

    /**
     * @return array<string, true>
     */
    private function earlierRoundsOf(ReviewRound $round): array
    {
        $earlier = array_filter($this->rounds->ofChange($round), static fn (ReviewRound $other): bool => $other->number() < $round->number());

        return array_fill_keys(array_map(static fn (ReviewRound $other): string => $other->id()->toRfc4122(), array_values($earlier)), true);
    }

    // The latest escalation of each finding wins, because a finding whose files changed recurs under a new key with a verdict of its own.
    /**
     * @param array<string, true> $rounds
     * @param list<string>        $open
     *
     * @return array<string, string>
     */
    private function keysOf(array $rounds, array $open): array
    {
        $keys = [];

        foreach ($this->escalationsOf($rounds) as $escalation) {
            if (in_array($escalation->finding, $open, true)) {
                $keys[$escalation->finding] = $escalation->key;
            }
        }

        return $keys;
    }

    /**
     * @param array<string, true> $rounds
     *
     * @return list<EscalationReviewed|EscalationVerdictReused>
     */
    private function escalationsOf(array $rounds): array
    {
        $escalations = [];

        // Only the events naming one of the rounds, because every review asks this and the ledger holds the events of every round.
        foreach ($this->ledger->readMentioning(array_map(strval(...), array_keys($rounds))) as $recorded) {
            $class = self::KINDS[$recorded->event->kind->value] ?? null;
            $escalation = null === $class ? null : $this->payloads->of($recorded, $class);

            if (($escalation instanceof EscalationReviewed || $escalation instanceof EscalationVerdictReused) && isset($rounds[$escalation->round])) {
                $escalations[] = $escalation;
            }
        }

        return $escalations;
    }
}
