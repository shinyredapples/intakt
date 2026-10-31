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
use Symfony\Component\Serializer\SerializerInterface;

// The finding ledger of each change, kept between its rounds; the writes run inside the caller's transaction, on the change's locked row.
final readonly class StoredFindings
{
    public function __construct(
        private EntityManagerInterface $entities,
        private SerializerInterface $serializer,
    ) {
    }

    public function openRound(string $project, string $change): ChangeRound
    {
        return $this->locked($project, $change)->openRound();
    }

    /**
     * @return list<Finding>
     */
    public function openFor(ReviewRound $round): array
    {
        $stored = $this->entities->find(ChangeFindings::class, ['project' => $round->project(), 'change' => $round->change()]);

        return $stored instanceof ChangeFindings ? $this->ledgerOf($stored)->openForReviewers() : [];
    }

    // Every verdict of the round at once, when it completes, so each reviewer of a round was shown the same open findings.
    public function recordVerdictsOf(ReviewRound $round): void
    {
        $stored = $this->locked($round->project(), $round->change());
        $ledger = $this->ledgerOf($stored);

        foreach ($this->verdictsOf($round) as $verdict) {
            $ledger->recordVerdict($round->number(), $verdict);
        }

        $stored->recordFindings(json_encode($ledger->all(), JSON_THROW_ON_ERROR));
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
        $stored = array_filter(array_map(static fn ($reviewer): ?string => $reviewer->verdictJson(), $round->reviewers()));

        return array_values(array_map(fn (string $json): Verdict => $this->serializer->deserialize($json, Verdict::class, 'json'), $stored));
    }
}
