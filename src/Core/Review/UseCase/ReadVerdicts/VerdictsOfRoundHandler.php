<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReadVerdicts;

use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Platform\Contract\AsQueryHandler;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\RoundVerdicts;
use Intakt\Core\Review\Contract\Standing;
use Intakt\Core\Review\Contract\VerdictsOfRound;
use Intakt\Core\Review\Contract\VerdictStanding;
use Intakt\Core\Review\Domain\ReviewRound;
use Symfony\Component\Uid\Uuid;

#[AsQueryHandler]
final readonly class VerdictsOfRoundHandler
{
    public function __construct(
        private EntityManagerInterface $entities,
        private StoredVerdicts $verdicts,
    ) {
    }

    public function __invoke(VerdictsOfRound $query): RoundVerdicts
    {
        $round = $this->storedRound($query->roundId);
        $standing = $round->standingAt($query->current, $query->move);

        return new RoundVerdicts($standing, $this->countingOf($round, $standing));
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
     * @return list<PanelVerdict>
     */
    private function countingOf(ReviewRound $round, VerdictStanding $standing): array
    {
        if (Standing::Stale === $standing->standing) {
            return [];
        }

        // A replay plays a recorded review back, so its verdicts were never given on this change.
        if (ReplayMark::Replay === $round->replayMark()) {
            return [];
        }

        return $this->verdicts->of($round);
    }
}
