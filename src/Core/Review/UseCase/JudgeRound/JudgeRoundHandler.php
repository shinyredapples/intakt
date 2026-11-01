<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\JudgeRound;

use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\JudgedFindings;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\UseCase\ReadVerdicts\RoundNotFound;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;

// Runs after the round's completing transaction, never inside it, because the judge call must not hold the round's lock.
#[AsCommandHandler]
final readonly class JudgeRoundHandler
{
    public function __construct(
        private EntityManagerInterface $entities,
        private SerializerInterface $serializer,
        private Vendor $vendor,
        private RoundLedger $ledger,
    ) {
    }

    public function __invoke(JudgeRound $command): JudgedFindings
    {
        $round = $this->storedRound($command->roundId);
        $findings = $this->findingsOf($round);
        $judgement = $this->vendor->judge($round->project(), new JudgeRequest($round->task(), $findings));
        $judged = JudgedFindings::of($judgement, $findings);
        $this->ledger->judgeDone($round, $judged);

        return $judged;
    }

    private function storedRound(string $roundId): ReviewRound
    {
        if (!Uuid::isValid($roundId)) {
            throw RoundNotFound::for($roundId);
        }

        // Cleared because a worker keeps its entity manager between messages, and the verdicts other workers stored since are the truth.
        $this->entities->clear();

        return $this->entities->find(ReviewRound::class, Uuid::fromString($roundId)) ?? throw RoundNotFound::for($roundId);
    }

    /**
     * @return list<Finding>
     */
    private function findingsOf(ReviewRound $round): array
    {
        $findings = [];

        foreach ($round->reviewers() as $reviewer) {
            $json = $reviewer->verdictJson();

            if (null !== $json) {
                $verdict = $this->serializer->deserialize($json, Verdict::class, 'json');

                // Keyed by id, because the judge refuses a repeated id and the first report of a finding stands for it.
                foreach ($verdict->findings as $finding) {
                    $findings[$finding->id] ??= $finding;
                }
            }
        }

        return array_values($findings);
    }
}
