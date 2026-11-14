<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\CatchUpOwed;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FollowUp;
use Intakt\Core\Review\Contract\JudgedFindings;
use Intakt\Core\Review\Contract\JudgeDone;
use Intakt\Core\Review\Contract\JudgeDoneFinding;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReviewerBlocked;
use Intakt\Core\Review\Contract\ReviewerFailed;
use Intakt\Core\Review\Contract\ReviewerStarted;
use Intakt\Core\Review\Contract\ReviewerStopped;
use Intakt\Core\Review\Contract\ReviewStarted;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictReceived;
use Intakt\Core\Review\Domain\OnePerPlace;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\ReviewerForLoop\Contract\ProjectModes;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Serializer\SerializerInterface;

// Called inside the caller's transaction, so a refused event rolls the move it reports back with it.
final readonly class RoundLedger
{
    public function __construct(
        private Ledger $ledger,
        private ProjectModes $modes,
        #[Target('stored')]
        private SerializerInterface $serializer,
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

    public function reviewerStarted(RoundReviewer $reviewer): void
    {
        $this->append(LedgerEventKind::ReviewerStarted, new ReviewerStarted(...$this->identify($reviewer)));
    }

    // The mode goes with each verdict, because the mode of its moment is lost once the project is switched.
    public function verdictReceived(RoundReviewer $reviewer, Verdict $verdict): void
    {
        $this->append(LedgerEventKind::VerdictReceived, new VerdictReceived(
            ...$this->identify($reviewer),
            decision: $verdict->verdict,
            findings: count($verdict->findings),
            mode: $this->modes->of($reviewer->round()->project()),
        ));
    }

    // Written with the verdict in one transaction, so a substitute's verdict never counts without its owed catch-up.
    public function catchUpOwed(RoundReviewer $reviewer, Provider $substitute): void
    {
        $this->append(LedgerEventKind::CatchUpOwed, new CatchUpOwed(...$this->identify($reviewer), substitute: $substitute));
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

    // The judged findings go with the call, because the dashboard renders from the ledger alone and needs what each act id stands for.
    public function judgeDone(ReviewRound $round, JudgedFindings $judged): void
    {
        $act = array_map(static fn (Finding $finding): string => $finding->id, $judged->actList);

        $this->append(LedgerEventKind::JudgeDone, new JudgeDone(
            $round->id()->toRfc4122(),
            $act,
            array_map(static fn (FollowUp $followUp): string => $followUp->finding->id, $judged->followUps),
            $this->recordedFindings($judged->findings, $act),
        ));
    }

    /**
     * @param list<Finding> $findings
     * @param list<string>  $act
     *
     * @return list<JudgeDoneFinding>
     */
    // An act entry is recorded as the act list holds it (highest severity, merged ids), so the review page shows it the way the console does; the merged findings stay recorded for their ids.
    private function recordedFindings(array $findings, array $act): array
    {
        $entries = [];
        foreach (new OnePerPlace()->actList($findings, $act) as $entry) {
            $entries[$entry->id] = $entry;
        }

        $shown = array_map(static fn (Finding $finding): Finding => $entries[$finding->id] ?? $finding, $findings);

        return array_map(static fn (Finding $finding): JudgeDoneFinding => new JudgeDoneFinding($finding->id, $finding->severity, $finding->text, $finding->merged), $shown);
    }

    /**
     * @return array{round: string, reviewer: string, role: string, tier: Tier}
     */
    private function identify(RoundReviewer $reviewer): array
    {
        return ['round' => $reviewer->round()->id()->toRfc4122(), 'reviewer' => $reviewer->id()->toRfc4122(), 'role' => $reviewer->roleName(), 'tier' => $reviewer->tier()];
    }

    private function append(LedgerEventKind $kind, ReviewStarted|ReviewerStarted|VerdictReceived|CatchUpOwed|ReviewerFailed|ReviewerBlocked|ReviewerStopped|JudgeDone $payload): void
    {
        $result = $this->ledger->append(new LedgerEvent($kind, $this->serializer->serialize($payload, 'json')));

        if (!$result->succeeded()) {
            throw ReviewEventNotRecorded::for($kind->value, (string) $result->failure);
        }
    }
}
