<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Budget\Contract\StartDecision;
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
use Intakt\Core\Review\Contract\ReviewerBlocked;
use Intakt\Core\Review\Contract\ReviewerFailed;
use Intakt\Core\Review\Contract\ReviewerStarted;
use Intakt\Core\Review\Contract\ReviewerStopped;
use Intakt\Core\Review\Contract\ReviewStarted;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictReceived;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Shadow\Contract\ProjectModes;
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

    // The repository, base and task go with the start, because the dashboard starts the change's next round from the ledger alone and ledger rows are never back-filled.
    public function reviewStarted(ReviewRound $round, ?ChangeSource $source): void
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
        ));
    }

    // No round exists yet, so the event names the change and the number asked for; the decision is the reason, in the words the guard uses.
    public function shadowRoundHeld(RunReviewRound $command, Provider $provider, StartDecision $decision): void
    {
        $this->append(LedgerEventKind::ShadowRoundHeld, new ShadowRoundHeld($command->target->subject->project, $command->round->change, $provider, $decision->value));
    }

    public function reviewerStarted(RoundReviewer $reviewer): void
    {
        $this->append(LedgerEventKind::ReviewerStarted, new ReviewerStarted(...$this->identify($reviewer)));
    }

    // The mode goes with each verdict, because a verdict recorded as shadow must never count for a merge, even after the project is switched to live.
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
        $this->append(LedgerEventKind::JudgeDone, new JudgeDone(
            $round->id()->toRfc4122(),
            array_map(static fn (Finding $finding): string => $finding->id, $judged->actList),
            array_map(static fn (FollowUp $followUp): string => $followUp->finding->id, $judged->followUps),
            array_map(static fn (Finding $finding): JudgeDoneFinding => new JudgeDoneFinding($finding->id, $finding->severity, $finding->text), $judged->findings),
        ));
    }

    /**
     * @return array{round: string, reviewer: string, role: string, tier: Tier}
     */
    private function identify(RoundReviewer $reviewer): array
    {
        return ['round' => $reviewer->round()->id()->toRfc4122(), 'reviewer' => $reviewer->id()->toRfc4122(), 'role' => $reviewer->roleName(), 'tier' => $reviewer->tier()];
    }

    private function append(LedgerEventKind $kind, ReviewStarted|ShadowRoundHeld|ReviewerStarted|VerdictReceived|CatchUpOwed|ReviewerFailed|ReviewerBlocked|ReviewerStopped|JudgeDone $payload): void
    {
        $result = $this->ledger->append(new LedgerEvent($kind, $this->serializer->serialize($payload, 'json')));

        if (!$result->succeeded()) {
            throw ReviewEventNotRecorded::for($kind->value, (string) $result->failure);
        }
    }
}
