<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\ChangeFindings;
use Intakt\Core\Review\Domain\ChangeRound;
use Intakt\Core\Review\Domain\FindingLedger;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\TrackedFinding;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Workflow\WorkflowInterface;

// Writes run inside the caller's transaction on the change's locked row, because two rounds of one change must not interleave their updates of its ledger.
final readonly class StoredFindings
{
    public function __construct(
        private EntityManagerInterface $entities,
        private SerializerInterface $serializer,
        #[Target('review_round')]
        private WorkflowInterface $machine,
    ) {
    }

    // The open findings are taken once here, under the change's lock, and stored with the round, because a reviewer that read the ledger at its own start could be told what another round of the change recorded meanwhile.
    public function openRound(string $project, string $change): ChangeRound
    {
        $stored = $this->locked($project, $change);

        return $stored->openRound(json_encode($this->ledgerOf($stored)->openForReviewers(), JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<Finding>
     */
    public function openFor(ReviewRound $round): array
    {
        $json = $round->openFindingsJson();

        // A round stored before the ledger existed kept no open findings, and had none to tell.
        if (null === $json) {
            return [];
        }

        /** @var list<Finding> $open */
        $open = $this->serializer->deserialize($json, Finding::class.'[]', 'json');

        return $open;
    }

    // Completing and recording are one step, so the verdicts of a round reach the ledger exactly once: a second completion finds the guard closed.
    public function completeWhenEveryReviewerIsIn(ReviewRound $round): void
    {
        if (!$this->machine->can($round, ReviewRound::COMPLETION)) {
            return;
        }

        $this->machine->apply($round, ReviewRound::COMPLETION);
        $this->recordVerdictsOf($round);
    }

    // Every verdict of the round at once, when it completes, so a round reaches the ledger as one step that the recorded round number orders against the other rounds of the change.
    private function recordVerdictsOf(ReviewRound $round): void
    {
        $stored = $this->locked($round->project(), $round->change());

        if ($stored->hasRecordedAfter($round->number())) {
            return;
        }

        $ledger = $this->ledgerOf($stored);

        foreach ($this->verdictsOf($round) as $verdict) {
            $ledger->recordVerdict($round->number(), $verdict);
        }

        $stored->recordFindings($round->number(), json_encode($ledger->all(), JSON_THROW_ON_ERROR));
    }

    private function locked(string $project, string $change): ChangeFindings
    {
        $stored = $this->entities->find(ChangeFindings::class, ['project' => $project, 'change' => $change], LockMode::PESSIMISTIC_WRITE);

        if ($stored instanceof ChangeFindings) {
            return $stored;
        }

        $stored = new ChangeFindings($project, $change);
        $this->entities->persist($stored);

        return $stored;
    }

    private function ledgerOf(ChangeFindings $stored): FindingLedger
    {
        /** @var list<TrackedFinding> $tracked */
        $tracked = $this->serializer->deserialize($stored->findingsJson(), TrackedFinding::class.'[]', 'json');

        return new FindingLedger($tracked);
    }

    /**
     * @return list<Verdict>
     */
    private function verdictsOf(ReviewRound $round): array
    {
        $stored = array_filter(array_map(static fn ($reviewer): ?string => $reviewer->verdictJson(), $round->reviewers()), static fn (?string $json): bool => null !== $json);

        return array_values(array_map(fn (string $json): Verdict => $this->serializer->deserialize($json, Verdict::class, 'json'), $stored));
    }
}
