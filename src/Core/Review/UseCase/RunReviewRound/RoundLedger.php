<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolutionFailure;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Core\Review\Contract\CatchUpOwed;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\EscalationReviewFailed;
use Intakt\Core\Review\Contract\EscalationVerdictKept;
use Intakt\Core\Review\Contract\EscalationVerdictReused;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FindingIds;
use Intakt\Core\Review\Contract\FollowUp;
use Intakt\Core\Review\Contract\JudgedFindings;
use Intakt\Core\Review\Contract\JudgeDone;
use Intakt\Core\Review\Contract\JudgeDoneFinding;
use Intakt\Core\Review\Contract\JudgingFailed;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\RecurringP1Found;
use Intakt\Core\Review\Contract\ReviewerBlocked;
use Intakt\Core\Review\Contract\ReviewerFailed;
use Intakt\Core\Review\Contract\ReviewerFindingsRecorded;
use Intakt\Core\Review\Contract\ReviewerStarted;
use Intakt\Core\Review\Contract\ReviewerStopped;
use Intakt\Core\Review\Contract\ReviewStarted;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictReceived;
use Intakt\Core\Review\Domain\OnePerPlace;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Review\Infrastructure\FindingKeys;
use Intakt\Core\ReviewerForLoop\Contract\ProjectModes;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\SerializerInterface;

// Called inside the caller's transaction, so a refused event rolls the move it reports back with it.
final readonly class RoundLedger
{
    public function __construct(
        private Ledger $ledger,
        private ProjectModes $modes,
        #[Target('stored')]
        private SerializerInterface $serializer,
        private TierResolver $tiers,
        private Redactor $redactor,
        private FindingKeys $keys,
    ) {
    }

    // The repository, base, task and quorum go with the start, because the dashboard starts the change's next round and shows its outcome from the ledger alone, and ledger rows are never back-filled.
    public function reviewStarted(ReviewRound $round, QuorumPolicy $quorum, ?ChangeSource $source): void
    {
        $this->append(LedgerEventKind::ReviewStarted, new ReviewStarted(
            $round->id()->toRfc4122(),
            $round->project(),
            count($round->reviewers()),
            $round->change(),
            $round->number(),
            $round->task(),
            $source?->repository,
            $source?->base,
            $quorum,
        ));
    }

    // The model goes with the start, because the dashboard names a reviewer by it from the ledger alone and a later change of the tier's model must not rename it.
    public function reviewerStarted(RoundReviewer $reviewer): void
    {
        $this->append(LedgerEventKind::ReviewerStarted, new ReviewerStarted(...$this->identify($reviewer), model: $this->modelOf($reviewer)));
    }

    // The mode goes with each verdict, because the mode of its moment is lost once the project is switched.
    public function verdictReceived(RoundReviewer $reviewer, Verdict $verdict): void
    {
        $this->append(LedgerEventKind::VerdictReceived, new VerdictReceived(
            ...$this->identify($reviewer),
            decision: $verdict->verdict,
            findings: count($verdict->findings),
            mode: $this->modes->of($reviewer->round()->project()),
            reason: $verdict->blockedReason(),
        ));
    }

    // Under the ids of the change's finding ledger, because those are the ids the act list and the judge's merges name, so the dashboard can tell which reviewers reported an act entry.
    public function reviewerFindingsRecorded(ReportedFindings $reported): void
    {
        $this->append(LedgerEventKind::ReviewerFindingsRecorded, new ReviewerFindingsRecorded(
            $reported->reviewer->round()->id()->toRfc4122(),
            $reported->reviewer->id()->toRfc4122(),
            $reported->findings,
            $reported->severities,
        ));
    }

    // Written with the verdict in one transaction, so a substitute's verdict never counts without its owed catch-up; the substitute's model goes with it, because the dashboard then names the reviewer by the model that answered.
    public function catchUpOwed(RoundReviewer $reviewer, Provider $substitute): void
    {
        $this->append(LedgerEventKind::CatchUpOwed, new CatchUpOwed(
            ...$this->identify($reviewer),
            substitute: $substitute,
            model: $this->substituteModelOf($reviewer, $substitute),
        ));
    }

    public function reviewerFailed(RoundReviewer $reviewer, string $failure): void
    {
        $this->append(LedgerEventKind::ReviewerFailed, new ReviewerFailed(...$this->identify($reviewer), failure: $failure));
    }

    public function reviewerBlocked(RoundReviewer $reviewer, string $reason): void
    {
        $this->append(LedgerEventKind::ReviewerBlocked, new ReviewerBlocked(...$this->identify($reviewer), reason: $reason));
    }

    // Answers every stop request of this reviewer recorded before it, because the ledger orders them by place.
    public function reviewerStopped(RoundReviewer $reviewer): void
    {
        $this->append(LedgerEventKind::ReviewerStopped, new ReviewerStopped(...$this->identify($reviewer)));
    }

    // The only trace of a judging that failed for good, because Messenger dispatches its failure message instead of parking it in the failure transport.
    public function judgingFailed(ReviewRound $round): void
    {
        $this->append(LedgerEventKind::JudgingFailed, new JudgingFailed($round->id()->toRfc4122()));
    }

    // The judged findings go with the call, because the dashboard renders from the ledger alone and needs what each act id stands for.
    /**
     * @param array<string, Decision> $votes
     */
    public function judgeDone(ReviewRound $round, JudgedFindings $judged, array $votes): void
    {
        $act = array_map(static fn (Finding $finding): string => $finding->id, $judged->actList);

        $this->append(LedgerEventKind::JudgeDone, new JudgeDone(
            $round->id()->toRfc4122(),
            new FindingIds(...$act),
            new FindingIds(...array_map(static fn (FollowUp $followUp): string => $followUp->finding->id, $judged->followUps)),
            $this->recordedFindings($judged, $act),
            $judged->dropped,
            $judged->sameCause,
            $votes,
            $this->p1KeysOf($round, $judged),
            $judged->fallback,
        ));
    }

    public function recurringP1Found(RecurringP1Found $found): void
    {
        $this->append(LedgerEventKind::RecurringP1Found, $found);
    }

    public function escalationVerdictReused(EscalationVerdictReused $reused): void
    {
        $this->append(LedgerEventKind::EscalationVerdictReused, $reused);
    }

    public function escalationReviewed(EscalationVerdictKept $reviewed): void
    {
        $this->append(LedgerEventKind::EscalationReviewed, $reviewed);
    }

    public function escalationFailed(EscalationReviewFailed $failed): void
    {
        $this->append(LedgerEventKind::EscalationFailed, $failed);
    }

    // Keyed at the head the round judged, because only the reviewed checkout holds the files a P1 named as the reviewers saw them.
    /**
     * @return array<string, ?string>
     */
    private function p1KeysOf(ReviewRound $round, JudgedFindings $judged): array
    {
        $keys = [];

        foreach ($judged->findings as $finding) {
            if (Severity::P1 === $finding->severity) {
                $keys[$finding->id] = $this->keys->of($finding, $round->checkoutRoot())?->value;
            }
        }

        return $keys;
    }

    // An act entry is recorded as the act list holds it (highest severity, merged ids), so the review page shows it the way the console does; the merged findings stay recorded for their ids, each with its own judge's note, because the act list read back (StoredFindings::actListOf()) gives an entry its own note or else the first of a finding it merged.
    /**
     * @param list<string> $act
     *
     * @return list<JudgeDoneFinding>
     */
    private function recordedFindings(JudgedFindings $judged, array $act): array
    {
        $findings = $judged->findings;
        $entries = [];
        foreach (new OnePerPlace()->actList($findings, $act, iterator_to_array($judged->sameCause)) as $entry) {
            $entries[$entry->id] = $entry;
        }

        $shown = array_map(static fn (Finding $finding): Finding => $entries[$finding->id] ?? $finding, $findings);

        return array_map(fn (Finding $finding): JudgeDoneFinding => new JudgeDoneFinding($finding->id, $finding->severity, $finding->text, $finding->merged, $finding->file, $finding->line, $this->storedNote($judged->notes->noteOf($finding->id))), $shown);
    }

    // Redacted and then trimmed, because the note is model text that may quote a held credential, and a credential held with edge whitespace leaves that whitespace behind.
    private function storedNote(?string $note): ?string
    {
        if (null === $note) {
            return null;
        }

        $stored = trim($this->redactor->redact($note));

        return '' === $stored ? null : $stored;
    }

    // A tier that does not resolve still records the start, without a model, because the reviewer's own call then fails on its usual path.
    private function modelOf(RoundReviewer $reviewer): ?string
    {
        try {
            return $this->tiers->resolve($reviewer->round()->project(), $reviewer->tier())->model;
        } catch (TierResolutionFailure) {
            return null;
        }
    }

    // The first substitute on that provider, because the vendor tries the tier's substitutes in order and an outage takes a whole provider out.
    private function substituteModelOf(RoundReviewer $reviewer, Provider $substitute): ?string
    {
        try {
            $substitutes = $this->tiers->substitutes($reviewer->round()->project(), $reviewer->tier());
        } catch (TierResolutionFailure) {
            return null;
        }

        return array_find($substitutes, static fn (ResolvedModel $model): bool => $substitute === $model->provider)?->model;
    }

    /**
     * @return array{round: string, reviewer: string, role: string, tier: Tier}
     */
    private function identify(RoundReviewer $reviewer): array
    {
        return ['round' => $reviewer->round()->id()->toRfc4122(), 'reviewer' => $reviewer->id()->toRfc4122(), 'role' => $reviewer->roleName(), 'tier' => $reviewer->tier()];
    }

    private function append(LedgerEventKind $kind, ReviewStarted|ReviewerStarted|VerdictReceived|CatchUpOwed|ReviewerFailed|ReviewerBlocked|ReviewerStopped|JudgeDone|JudgingFailed|ReviewerFindingsRecorded|RecurringP1Found|EscalationVerdictReused|EscalationVerdictKept|EscalationReviewFailed $payload): void
    {
        $result = $this->ledger->append(new LedgerEvent($kind, $this->serializer->serialize($payload, JsonEncoder::FORMAT)));

        if (!$result->succeeded()) {
            throw ReviewEventNotRecorded::for($kind->value, (string) $result->failure);
        }
    }
}
