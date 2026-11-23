<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Review\Contract\ChangeAddress;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\JudgeDone;
use Intakt\Core\Review\Contract\JudgeDoneFinding;
use Intakt\Core\Review\Contract\ResolvedFinding;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\ChangeFindings;
use Intakt\Core\Review\Domain\ChangeRound;
use Intakt\Core\Review\Domain\FindingLedger;
use Intakt\Core\Review\Domain\OnePerPlace;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Review\Domain\RoundTransition;
use Intakt\Core\Review\Domain\TrackedFinding;
use Intakt\Core\Review\Infrastructure\ReviewerAnswer\Verdict as ReviewerAnswer;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Workflow\WorkflowInterface;

// Writes run inside the caller's transaction on the change's locked row, because two rounds of one change must not interleave their updates of its ledger.
final readonly class StoredFindings
{
    public function __construct(
        private EntityManagerInterface $entities,
        #[Target('stored')]
        private SerializerInterface $serializer,
        #[Target('review_round')]
        private WorkflowInterface $machine,
    ) {
    }

    // The open findings are taken once here, under the change's lock, and stored with the round, because a reviewer that read the ledger at its own start could be told what another round of the change recorded meanwhile; under the canonical address, before the row is created, so two spellings of one pull request lock one row and number one sequence.
    public function openRound(string $project, string $change): ChangeRound
    {
        $stored = $this->locked($project, ChangeAddress::canonical($change));

        return $stored->openRound($this->written($this->ledgerOf($stored)->openForReviewers()));
    }

    /**
     * @return list<Finding>
     */
    public function openFor(ReviewRound $round): array
    {
        // None, because a round stored before the ledger existed kept no open findings and had none to tell.
        return $this->findingsIn($round->openFindingsJson());
    }

    // Completing and recording are one step, so the verdicts of a round reach the ledger exactly once: a second completion finds the guard closed and reports nothing.
    /**
     * @return list<ReportedFindings>
     */
    public function completeWhenEveryReviewerIsIn(ReviewRound $round): array
    {
        if (!$this->machine->can($round, RoundTransition::Complete->value)) {
            return [];
        }

        $this->machine->apply($round, RoundTransition::Complete->value);

        return $this->recordVerdictsOf($round);
    }

    /**
     * @return list<Finding>
     */
    public function reportedIn(ReviewRound $round): array
    {
        // None, because a round completed before migration Version20261101120000 kept no reported findings and was never queued for judging.
        return $this->findingsIn($round->reportedFindingsJson());
    }

    // One entry per place and per cause, because two reviewers reporting one file and line, or one change the judge linked, send the builder one fix; the act ids, the links and the notes are the judge's as recorded, so a note is read already redacted. None before the judge has answered.
    /**
     * @return list<Finding>
     */
    public function actListOf(ReviewRound $round, ?JudgeDone $judged): array
    {
        if (!$judged instanceof JudgeDone) {
            return [];
        }

        $notes = array_filter(array_column(array_map(static fn (JudgeDoneFinding $finding): array => [$finding->id, $finding->judgeNote], $judged->findings), 1, 0), static fn (?string $note): bool => null !== $note);
        $reported = array_map(static fn (Finding $finding): Finding => isset($notes[$finding->id]) ? $finding->withJudgeNote($notes[$finding->id]) : $finding, $this->reportedIn($round));

        return new OnePerPlace()->actList($reported, [...$judged->act], iterator_to_array($judged->sameCause));
    }

    /**
     * @return list<Finding>
     */
    public function resolvedBy(ReviewRound $round): array
    {
        // None, because a round completed before migration Version20261101130000 kept no resolved findings and was never queued for indexing.
        return $this->findingsIn($round->resolvedFindingsJson());
    }

    /**
     * @return list<string>
     */
    public function resolvedIdsOf(ReviewRound $round): array
    {
        $json = $round->resolvedFindingIdsJson();

        // None, because a round completed before migration Version20261112110000 kept its resolved findings under its reviewer's ids, which can name other findings of the ledger.
        if (null === $json) {
            return [];
        }

        /** @var list<string> $ids */
        $ids = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $ids;
    }

    // False when the round is judged already, so a judgement is recorded once however often the round is judged; the caller holds the round's lock.
    public function markJudged(ReviewRound $round): bool
    {
        if (!$this->machine->can($round, RoundTransition::Judge->value)) {
            return false;
        }

        $this->machine->apply($round, RoundTransition::Judge->value);

        return true;
    }

    // The lock a completing round takes after its own, so two judgings of one change read each other's judge_done in turn; under the address the round stored, which is canonical unless an old spelling collided with another (Version20261122100000) and then names its own row; without the plain existence read, because under MySQL's REPEATABLE READ that read would fix the snapshot before the wait and hide the other judging's judge_done (a judged round completed through this row, so it exists).
    public function lockChangeOf(ReviewRound $round): void
    {
        $this->lockedRow($round->project(), $round->change()) ?? throw ChangeFindingsNotFound::for($round->project(), $round->change());
    }

    // Every verdict of the round at once, when it completes, so a round reaches the ledger as one step that the recorded round number orders against the other rounds of the change.
    /**
     * @return list<ReportedFindings>
     */
    private function recordVerdictsOf(ReviewRound $round): array
    {
        $stored = $this->locked($round->project(), $round->change());
        $ledger = $this->ledgerFor($round, $stored);

        $reported = array_map(fn (RoundReviewer $reviewer): ReportedFindings => $this->reportedBy($reviewer, $ledger, $round->number()), $this->answeredIn($round));

        $round->keepReportedFindings($this->written($ledger->openReportedIn($round->number())));

        // The round still keeps what it reported, under its ledger's ids, so its judge sees its findings although its verdicts must not undo the newer round's; it keeps nothing as resolved, because a resolution its ledger accepted never reaches the stored one, where the newer round may hold the finding open.
        if ($stored->hasRecordedAfter($round->number())) {
            $round->keepResolvedFindings($this->written([]));
            $round->keepResolvedFindingIds($this->serializer->serialize([], JsonEncoder::FORMAT));

            return $reported;
        }

        $resolved = $this->resolvedIn($round, $ledger);
        // Under the ledger's id, because reviewers name findings by it and two findings may read alike.
        $round->keepResolvedFindings($this->written(array_map(static fn (TrackedFinding $tracked): Finding => $tracked->latest->withId($tracked->id), $resolved)));
        $round->keepResolvedFindingIds($this->serializer->serialize(array_map(static fn (TrackedFinding $tracked): string => $tracked->id, $resolved), JsonEncoder::FORMAT));
        $stored->recordFindings($round->number(), $this->written($ledger->all()));

        return $reported;
    }

    private function locked(string $project, string $change): ChangeFindings
    {
        $this->createIfMissing($project, $change);

        return $this->lockedRow($project, $change) ?? throw ChangeFindingsNotFound::for($project, $change);
    }

    private function lockedRow(string $project, string $change): ?ChangeFindings
    {
        // Locked with a write, because on SQLite only a write takes the lock, and a snapshot gone stale then fails here, where the transaction starts over, rather than in the flush, which closes the entity manager.
        $this->entities->getConnection()->executeStatement('UPDATE change_findings SET rounds = rounds WHERE project = ? AND change_ref = ?', [$project, $change]);

        return $this->entities->find(ChangeFindings::class, ['project' => $project, 'change' => $change], LockMode::PESSIMISTIC_WRITE);
    }

    // A row that does not exist yet cannot be locked, so two first rounds of a change can both find none; the one whose insert loses takes the other's row instead of failing.
    private function createIfMissing(string $project, string $change): void
    {
        $connection = $this->entities->getConnection();

        // Looked up without a lock, because a lock on a missing row in MySQL is a gap lock that makes two such inserts deadlock.
        if (false !== $connection->fetchOne('SELECT 1 FROM change_findings WHERE project = ? AND change_ref = ?', [$project, $change])) {
            return;
        }

        try {
            // In a savepoint, because PostgreSQL refuses every further statement of a transaction in which one failed.
            $connection->transactional(static fn (Connection $inner): int|string => $inner->insert('change_findings', ['project' => $project, 'change_ref' => $change, 'rounds' => ChangeFindings::NO_ROUNDS, 'findings' => ChangeFindings::NO_FINDINGS, 'recorded_round' => ChangeFindings::NO_ROUNDS]));
        } catch (UniqueConstraintViolationException) {
            // Ignored, because then another round of the change created the row meanwhile, and the lock that follows waits for that round.
        }
    }

    /**
     * @return list<Finding>
     */
    private function findingsIn(?string $json): array
    {
        if (null === $json) {
            return [];
        }

        return array_values($this->serializer->deserialize($json, Finding::class.'[]', JsonEncoder::FORMAT));
    }

    // A round overtaken by a newer recorded one gives its new findings ids of its own, because the ids of its ledger, which is never stored, would name other findings of the stored one later.
    private function ledgerFor(ReviewRound $round, ChangeFindings $stored): FindingLedger
    {
        if ($stored->hasRecordedAfter($round->number())) {
            return FindingLedger::apartFor($round->number(), $this->trackedIn($stored));
        }

        return $this->ledgerOf($stored);
    }

    // Each id keeps the severity this reviewer gave it, because another reviewer may repeat the same tracked finding at another one; the ledger returns one id per reported finding, in order, and the higher severity stands where a reviewer reports one id twice.
    private function reportedBy(RoundReviewer $reviewer, FindingLedger $ledger, int $number): ReportedFindings
    {
        $verdict = $this->verdictOf($reviewer);
        $ids = $ledger->recordVerdict($number, $verdict);
        $severities = [];

        foreach ($ids as $index => $id) {
            $severity = $verdict->findings[$index]->severity->value;
            $severities[$id] = min($severities[$id] ?? $severity, $severity);
        }

        return new ReportedFindings($reviewer, $ids, $severities);
    }

    private function ledgerOf(ChangeFindings $stored): FindingLedger
    {
        return new FindingLedger($this->trackedIn($stored));
    }

    /**
     * @param list<Finding>|list<TrackedFinding> $findings
     */
    private function written(array $findings): string
    {
        return $this->serializer->serialize($findings, JsonEncoder::FORMAT);
    }

    /**
     * @return list<TrackedFinding>
     */
    private function trackedIn(ChangeFindings $stored): array
    {
        return array_values($this->serializer->deserialize($stored->findingsJson(), TrackedFinding::class.'[]', JsonEncoder::FORMAT));
    }

    /**
     * @return list<RoundReviewer>
     */
    private function answeredIn(ReviewRound $round): array
    {
        return array_values(array_filter($round->reviewers(), static fn (RoundReviewer $reviewer): bool => null !== $reviewer->verdictJson()));
    }

    /**
     * @return list<TrackedFinding>
     */
    private function resolvedIn(ReviewRound $round, FindingLedger $ledger): array
    {
        $claimed = array_merge([], ...array_map(static fn (Verdict $verdict): array => $verdict->isFailedReview() ? [] : array_map(static fn (ResolvedFinding $resolved): string => $resolved->id, $verdict->resolved), $this->verdictsOf($round)));

        // The ledger decides, because a claim the ledger refused (a finding reported again in the same round) leaves the finding open.
        return array_values(array_filter($ledger->all(), static fn (TrackedFinding $tracked): bool => !$tracked->isOpen() && in_array($tracked->id, $claimed, true)));
    }

    /**
     * @return list<Verdict>
     */
    private function verdictsOf(ReviewRound $round): array
    {
        return array_map($this->verdictOf(...), $this->answeredIn($round));
    }

    private function verdictOf(RoundReviewer $reviewer): Verdict
    {
        // Read as the reviewer's answer format, because a stored round holds the JSON that format defines.
        return $this->serializer->deserialize((string) $reviewer->verdictJson(), ReviewerAnswer::class, JsonEncoder::FORMAT)->toVerdict();
    }
}
